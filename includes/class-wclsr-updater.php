<?php
defined( 'ABSPATH' ) || exit;

/**
 * Checks GitHub Releases for a newer version of the plugin and hooks into
 * WordPress's native update system so "Update Now" appears in the Plugins list.
 *
 * Setup:
 *   1. Push your plugin zip to a GitHub Release (tag must match the version, e.g. v1.1.0).
 *   2. Set WCLSR_GITHUB_REPO in wc-live-shipping-rates.php to "username/repo".
 *   3. That's it — WordPress will check automatically.
 */
class WCLSR_Updater {

	private $plugin_slug;
	private $plugin_file;
	private $current_version;
	private $github_repo;
	private $github_token;
	private $cache_key;
	private $cache_ttl = 43200; // 12 hours

	public function __construct( $plugin_file, $github_repo, $current_version, $github_token = '' ) {
		$this->plugin_file     = $plugin_file;
		$this->plugin_slug     = plugin_basename( $plugin_file );
		$this->github_repo     = $github_repo;
		$this->current_version = $current_version;
		$this->github_token    = $github_token;
		$this->cache_key       = 'wclsr_update_' . md5( $github_repo );

		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_update' ] );
		add_filter( 'plugins_api', [ $this, 'plugin_info' ], 20, 3 );
		add_filter( 'upgrader_post_install', [ $this, 'after_install' ], 10, 3 );
	}

	/**
	 * Fetch latest release info from GitHub, cached for 12 hours.
	 */
	private function get_release_info() {
		$cached = get_transient( $this->cache_key );
		if ( $cached !== false ) return $cached;

		$url     = "https://api.github.com/repos/{$this->github_repo}/releases/latest";
		$headers = [
			'User-Agent' => 'WC-Live-Shipping-Rates/' . $this->current_version,
			'Accept'     => 'application/vnd.github+json',
		];
		if ( $this->github_token ) {
			$headers['Authorization'] = 'Bearer ' . $this->github_token;
		}

		$response = wp_remote_get( $url, [ 'headers' => $headers, 'timeout' => 10 ] );

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			set_transient( $this->cache_key, null, 300 ); // cache failure briefly
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ) );
		if ( empty( $data->tag_name ) ) return null;

		$release = (object) [
			'version'     => ltrim( $data->tag_name, 'v' ),
			'zip_url'     => '',
			'description' => $data->body ?? '',
			'released_at' => $data->published_at ?? '',
		];

		// Find the plugin zip asset
		foreach ( $data->assets ?? [] as $asset ) {
			if ( substr( $asset->name, -4 ) === '.zip' ) {
				$release->zip_url = $asset->browser_download_url;
				break;
			}
		}

		// Fall back to the auto-generated source zip if no explicit asset uploaded
		if ( empty( $release->zip_url ) ) {
			$release->zip_url = "https://github.com/{$this->github_repo}/archive/refs/tags/{$data->tag_name}.zip";
		}

		set_transient( $this->cache_key, $release, $this->cache_ttl );
		return $release;
	}

	/**
	 * Inject our update into WordPress's plugin update transient.
	 */
	public function check_for_update( $transient ) {
		if ( empty( $transient->checked ) ) return $transient;

		$release = $this->get_release_info();
		if ( ! $release ) return $transient;

		if ( version_compare( $release->version, $this->current_version, '>' ) ) {
			$transient->response[ $this->plugin_slug ] = (object) [
				'slug'        => dirname( $this->plugin_slug ),
				'plugin'      => $this->plugin_slug,
				'new_version' => $release->version,
				'url'         => "https://github.com/{$this->github_repo}",
				'package'     => $release->zip_url,
				'icons'       => [],
				'banners'     => [],
				'tested'      => '9.0',
				'requires_php'=> '7.4',
			];
		}

		return $transient;
	}

	/**
	 * Populate the plugin info modal (the "View version X details" popup).
	 */
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
				'description' => '<p>Fetch live shipping rates from Canada Post, UPS, and Purolator at WooCommerce checkout.</p>',
				'changelog'   => nl2br( esc_html( $release->description ) ) ?: '<p>See GitHub releases for changelog.</p>',
			],
		];
	}

	/**
	 * Rename the extracted folder to match the expected plugin slug after install.
	 * GitHub zips extract with a hash-suffixed folder name; this corrects it.
	 */
	public function after_install( $response, $hook_extra, $result ) {
		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_slug ) {
			return $response;
		}

		global $wp_filesystem;
		$expected_dir = WP_PLUGIN_DIR . '/' . dirname( $this->plugin_slug );

		if ( $result['destination'] !== $expected_dir ) {
			$wp_filesystem->move( $result['destination'], $expected_dir );
			$result['destination'] = $expected_dir;
		}

		activate_plugin( $this->plugin_slug );
		return $result;
	}

	/**
	 * Force-clear the update cache (call after saving settings, for example).
	 */
	public static function clear_cache( $github_repo ) {
		delete_transient( 'wclsr_update_' . md5( $github_repo ) );
	}
}
