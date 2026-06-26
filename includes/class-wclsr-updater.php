<?php
defined( 'ABSPATH' ) || exit;

class WCLSR_Updater {

	private $plugin_slug;
	private $plugin_file;
	private $current_version;
	private $github_repo;
	private $cache_key;
	private $cache_ttl = 43200; // 12 hours

	public function __construct( $plugin_file, $github_repo, $current_version ) {
		$this->plugin_file     = $plugin_file;
		$this->plugin_slug     = plugin_basename( $plugin_file );
		$this->github_repo     = $github_repo;
		$this->current_version = $current_version;
		$this->cache_key       = 'wclsr_update_' . md5( $github_repo );

		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_update' ] );
		add_filter( 'plugins_api',                           [ $this, 'plugin_info' ], 20, 3 );
		add_filter( 'upgrader_post_install',                 [ $this, 'after_install' ], 10, 3 );
		add_filter( 'plugin_action_links_' . $this->plugin_slug, [ $this, 'add_action_links' ] );
		add_action( 'admin_init',                            [ $this, 'handle_force_check' ] );
		add_action( 'admin_notices',                         [ $this, 'maybe_show_update_notice' ] );
	}

	// -------------------------------------------------------------------------
	// GitHub API
	// -------------------------------------------------------------------------

	private function get_release_info( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( $this->cache_key );
			if ( $cached !== false ) return $cached;
		}

		$url      = "https://api.github.com/repos/{$this->github_repo}/releases/latest";
		$response = wp_remote_get( $url, [
			'timeout' => 10,
			'headers' => [
				'User-Agent' => 'WC-Live-Shipping-Rates/' . $this->current_version,
				'Accept'     => 'application/vnd.github+json',
			],
		] );

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			set_transient( $this->cache_key, null, 300 ); // cache failure for 5 min
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ) );
		if ( empty( $data->tag_name ) ) return null;

		$zip_url = '';
		foreach ( $data->assets ?? [] as $asset ) {
			if ( substr( $asset->name, -4 ) === '.zip' ) {
				$zip_url = $asset->browser_download_url;
				break;
			}
		}
		if ( ! $zip_url ) {
			$zip_url = "https://github.com/{$this->github_repo}/archive/refs/tags/{$data->tag_name}.zip";
		}

		$release = (object) [
			'version'     => ltrim( $data->tag_name, 'v' ),
			'zip_url'     => $zip_url,
			'description' => $data->body ?? '',
			'released_at' => $data->published_at ?? '',
		];

		set_transient( $this->cache_key, $release, $this->cache_ttl );
		return $release;
	}

	// -------------------------------------------------------------------------
	// WordPress update hooks
	// -------------------------------------------------------------------------

	public function check_for_update( $transient ) {
		if ( empty( $transient->checked ) ) return $transient;

		$release = $this->get_release_info();
		if ( ! $release ) return $transient;

		if ( version_compare( $release->version, $this->current_version, '>' ) ) {
			$transient->response[ $this->plugin_slug ] = (object) [
				'slug'         => dirname( $this->plugin_slug ),
				'plugin'       => $this->plugin_slug,
				'new_version'  => $release->version,
				'url'          => "https://github.com/{$this->github_repo}",
				'package'      => $release->zip_url,
				'icons'        => [],
				'banners'      => [],
				'tested'       => '9.0',
				'requires_php' => '7.4',
			];
		}

		return $transient;
	}

	public function plugin_info( $result, $action, $args ) {
		if ( $action !== 'plugin_information' ) return $result;
		if ( ! isset( $args->slug ) || $args->slug !== dirname( $this->plugin_slug ) ) return $result;

		$release = $this->get_release_info();
		if ( ! $release ) return $result;

		return (object) [
			'name'          => 'WC Live Shipping Rates',
			'slug'          => dirname( $this->plugin_slug ),
			'version'       => $release->version,
			'author'        => 'Your Name',
			'homepage'      => "https://github.com/{$this->github_repo}",
			'requires'      => '6.0',
			'tested'        => '9.0',
			'requires_php'  => '7.4',
			'download_link' => $release->zip_url,
			'last_updated'  => $release->released_at,
			'sections'      => [
				'description' => '<p>Live shipping rates from Canada Post, UPS, and Purolator at WooCommerce checkout.</p>',
				'changelog'   => '<p>' . nl2br( esc_html( $release->description ) ) . '</p>',
			],
		];
	}

	public function after_install( $response, $hook_extra, $result ) {
		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_slug ) {
			return $response;
		}

		global $wp_filesystem;
		$expected = WP_PLUGIN_DIR . '/' . dirname( $this->plugin_slug );

		if ( $result['destination'] !== $expected ) {
			$wp_filesystem->move( $result['destination'], $expected );
			$result['destination'] = $expected;
		}

		activate_plugin( $this->plugin_slug );
		return $result;
	}

	// -------------------------------------------------------------------------
	// Admin UI — Force Check link + notice
	// -------------------------------------------------------------------------

	public function add_action_links( $links ) {
		$nonce    = wp_create_nonce( 'wclsr_force_check' );
		$url      = add_query_arg( [ 'wclsr_force_check' => '1', '_wpnonce' => $nonce ], admin_url( 'plugins.php' ) );
		$links[]  = '<a href="' . esc_url( $url ) . '">Force Update Check</a>';
		return $links;
	}

	public function handle_force_check() {
		if ( empty( $_GET['wclsr_force_check'] ) ) return;
		if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'wclsr_force_check' ) ) return;
		if ( ! current_user_can( 'update_plugins' ) ) return;

		// Clear our GitHub cache so get_release_info() re-fetches
		delete_transient( $this->cache_key );

		// Clear WordPress's own plugin update transient so it re-runs all checks
		delete_site_transient( 'update_plugins' );

		// Trigger a fresh update check immediately so the notice appears now
		$release = $this->get_release_info( true );

		$redirect = remove_query_arg( [ 'wclsr_force_check', '_wpnonce' ] );
		if ( $release ) {
			$msg = version_compare( $release->version, $this->current_version, '>' )
				? 'update_available'
				: 'up_to_date';
			$redirect = add_query_arg( 'wclsr_check', $msg, $redirect );
		} else {
			$redirect = add_query_arg( 'wclsr_check', 'api_error', $redirect );
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	public function maybe_show_update_notice() {
		if ( empty( $_GET['wclsr_check'] ) ) return;
		if ( ! current_user_can( 'update_plugins' ) ) return;

		$status = sanitize_key( $_GET['wclsr_check'] );

		switch ( $status ) {
			case 'update_available':
				$release = $this->get_release_info();
				$version = $release ? $release->version : '?';
				$type    = 'warning';
				$msg     = sprintf(
					'<strong>WC Live Shipping Rates:</strong> Version <strong>%s</strong> is available. <a href="%s">Update now</a>.',
					esc_html( $version ),
					esc_url( admin_url( 'update-core.php' ) )
				);
				break;
			case 'up_to_date':
				$type = 'success';
				$msg  = '<strong>WC Live Shipping Rates:</strong> You are running the latest version (' . esc_html( $this->current_version ) . ').';
				break;
			default:
				$type = 'error';
				$msg  = '<strong>WC Live Shipping Rates:</strong> Could not reach GitHub to check for updates. Check your server\'s outbound internet access.';
		}

		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), $msg );
	}
}
