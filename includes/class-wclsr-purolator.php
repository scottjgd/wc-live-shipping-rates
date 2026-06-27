<?php
defined( 'ABSPATH' ) || exit;

/**
 * Purolator Live Rates — ship.purolator.com REST API (v1)
 *
 * Auth flow:
 *   POST https://ship.purolator.com/api/v1/auth/token
 *       { clientId, clientSecret, accountNumber }  →  { accessToken, expiresIn }
 *
 * Rate estimate:
 *   POST https://ship.purolator.com/api/v1/estimate
 *       Authorization: Bearer <accessToken>
 */
class WCLSR_Purolator extends WCLSR_Base {

	const BASE_URL = 'https://ship.purolator.com';

	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'wclsr_purolator';
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'Purolator (Live Rates)', 'wc-live-shipping-rates' );
		$this->method_description = __( 'Display live shipping rates from Purolator at checkout.', 'wc-live-shipping-rates' );
		$this->supports           = [ 'shipping-zones', 'instance-settings' ];

		$this->init();
	}

	public function init() {
		$this->init_form_fields();
		$this->init_settings();

		$this->title   = $this->get_option( 'title', 'Purolator' );
		$this->enabled = $this->get_option( 'enabled' );

		add_action( 'woocommerce_update_options_shipping_' . $this->id, [ $this, 'process_admin_options' ] );
	}

	public function init_form_fields() {
		$this->instance_form_fields = [
			'enabled' => [
				'title'   => __( 'Enable', 'wc-live-shipping-rates' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Purolator live rates', 'wc-live-shipping-rates' ),
				'default' => 'yes',
			],
			'title' => [
				'title'       => __( 'Method Title', 'wc-live-shipping-rates' ),
				'type'        => 'text',
				'default'     => 'Purolator',
				'desc_tip'    => true,
				'description' => __( 'Label shown to customers.', 'wc-live-shipping-rates' ),
			],
			'api_key' => [
				'title'       => __( 'API Key (Client ID)', 'wc-live-shipping-rates' ),
				'type'        => 'text',
				'description' => __( 'Your API Key from ship.purolator.com → Developer Portal → API Credentials.', 'wc-live-shipping-rates' ),
				'default'     => '',
				'desc_tip'    => true,
			],
			'api_password' => [
				'title'       => __( 'API Password (Client Secret)', 'wc-live-shipping-rates' ),
				'type'        => 'password',
				'description' => __( 'Your API Password from ship.purolator.com → Developer Portal → API Credentials.', 'wc-live-shipping-rates' ),
				'default'     => '',
				'desc_tip'    => true,
			],
			'account_number' => [
				'title'       => __( 'Account Number', 'wc-live-shipping-rates' ),
				'type'        => 'text',
				'description' => __( 'Your Purolator account number (e.g. 1234567). Will be zero-padded to 7 digits automatically.', 'wc-live-shipping-rates' ),
				'default'     => '',
				'desc_tip'    => true,
			],
			'origin_postal_code' => [
				'title'       => __( 'Origin Postal Code', 'wc-live-shipping-rates' ),
				'type'        => 'text',
				'description' => __( 'Postal code your shipments originate from (e.g. K1A0B1).', 'wc-live-shipping-rates' ),
				'default'     => '',
				'desc_tip'    => true,
			],
			'sandbox' => [
				'title'   => __( 'Development / Test Mode', 'wc-live-shipping-rates' ),
				'type'    => 'checkbox',
				'label'   => __( 'Using Development credentials from ship.purolator.com (test orders only)', 'wc-live-shipping-rates' ),
				'default' => 'yes',
			],
			'markup' => [
				'title'             => __( 'Rate Markup (%)', 'wc-live-shipping-rates' ),
				'type'              => 'number',
				'description'       => __( 'Optional percentage added on top of the carrier rate.', 'wc-live-shipping-rates' ),
				'default'           => '0',
				'desc_tip'          => true,
				'custom_attributes' => [ 'min' => '0', 'step' => '0.01' ],
			],
		];
	}

	public function calculate_shipping( $package = [] ) {
		$api_key        = trim( $this->get_option( 'api_key' ) );
		$api_password   = trim( $this->get_option( 'api_password' ) );
		$account_number = trim( $this->get_option( 'account_number' ) );
		$origin_postal  = preg_replace( '/\s+/', '', strtoupper( $this->get_option( 'origin_postal_code' ) ) );
		$markup_pct     = (float) $this->get_option( 'markup', 0 );

		if ( empty( $api_key ) || empty( $api_password ) || empty( $account_number ) || empty( $origin_postal ) ) {
			$this->log( 'Purolator: missing credentials or origin postal code — check plugin settings.' );
			return;
		}

		$dest_postal = preg_replace( '/\s+/', '', strtoupper( $package['destination']['postcode'] ?? '' ) );
		if ( empty( $dest_postal ) ) {
			$this->log( 'Purolator: no destination postal code.' );
			return;
		}

		// Purolator requires account number zero-padded to 7 digits
		$account_number = str_pad( $account_number, 7, '0', STR_PAD_LEFT );

		$weight_kg = $this->get_package_weight_kg( $package );
		$dims      = $this->get_package_dims_cm( $package );

		// Step 1 — get Bearer token (cached in transients)
		$token = $this->get_bearer_token( $api_key, $api_password, $account_number );
		if ( ! $token ) {
			return; // error already logged inside get_bearer_token()
		}

		// Step 2 — call the Estimate API
		$this->send_estimate_request( $token, $origin_postal, $dest_postal, $weight_kg, $dims, $markup_pct );
	}

	// -------------------------------------------------------------------------
	// Auth — token fetch & cache
	// -------------------------------------------------------------------------

	private function get_bearer_token( $api_key, $api_password, $account_number ) {
		$cache_key = 'wclsr_purolator_tok_' . md5( $api_key . $account_number );
		$cached    = get_transient( $cache_key );
		if ( $cached ) {
			return $cached;
		}

		$auth_url = self::BASE_URL . '/api/v1/auth/token';

		$this->log( 'Purolator: requesting auth token from ' . $auth_url );

		$response = wp_remote_post( $auth_url, [
			'headers' => [
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			],
			'body'    => wp_json_encode( [
				'clientId'      => $api_key,
				'clientSecret'  => $api_password,
				'accountNumber' => $account_number,
			] ),
			'timeout' => 15,
		] );

		if ( is_wp_error( $response ) ) {
			$this->log( 'Purolator: auth request failed — ' . $response->get_error_message() );
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code !== 200 ) {
			$this->log( "Purolator: auth returned HTTP $code — " . substr( $body, 0, 600 ) );
			return null;
		}

		$data  = json_decode( $body, true );
		$token = $data['accessToken'] ?? '';

		if ( empty( $token ) ) {
			$this->log( 'Purolator: auth succeeded but no accessToken in response — ' . substr( $body, 0, 400 ) );
			return null;
		}

		$expires_in = (int) ( $data['expiresIn'] ?? 3600 );
		set_transient( $cache_key, $token, max( 60, $expires_in - 300 ) );

		$this->log( 'Purolator: auth token obtained (expires in ' . $expires_in . 's).' );

		return $token;
	}

	// -------------------------------------------------------------------------
	// Rate estimate
	// -------------------------------------------------------------------------

	private function send_estimate_request( $token, $origin_postal, $dest_postal, $weight_kg, $dims, $markup_pct ) {
		$estimate_url = self::BASE_URL . '/api/v1/estimate';
		$ship_date    = $this->get_next_business_day();

		$payload = [
			'shipmentDate'    => $ship_date,
			'senderAddress'   => [
				'postalCode' => $origin_postal,
				'country'    => 'CA',
			],
			'receiverAddress' => [
				'postalCode' => $dest_postal,
				'country'    => 'CA',
			],
			'packageDetails'  => [
				[
					'quantity'   => 1,
					'weight'     => [
						'value'      => $weight_kg,
						'weightUnit' => 'kg',
					],
					'dimensions' => [
						'length'        => $dims['length'],
						'width'         => $dims['width'],
						'height'        => $dims['height'],
						'dimensionUnit' => 'cm',
					],
				],
			],
		];

		$this->log( sprintf(
			'Purolator: estimate request | origin=%s dest=%s weight=%.3fkg dims=%sx%sx%scm date=%s',
			$origin_postal, $dest_postal, $weight_kg,
			$dims['length'], $dims['width'], $dims['height'], $ship_date
		) );

		$response = wp_remote_post( $estimate_url, [
			'headers' => [
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
				'Authorization' => 'Bearer ' . $token,
			],
			'body'    => wp_json_encode( $payload ),
			'timeout' => 20,
		] );

		if ( is_wp_error( $response ) ) {
			$this->log( 'Purolator: estimate request failed — ' . $response->get_error_message() );
			return;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code !== 200 ) {
			$this->log( "Purolator: estimate returned HTTP $code — " . substr( $body, 0, 800 ) );
			return;
		}

		$this->log( 'Purolator: estimate response — ' . substr( $body, 0, 800 ) );

		$this->parse_rates( $body, $markup_pct );
	}

	private function parse_rates( $body, $markup_pct ) {
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			$this->log( 'Purolator: could not JSON-decode estimate response.' );
			return;
		}

		// The REST API may wrap rates under different keys depending on the version.
		// Try several common patterns; log the actual top-level keys so we can adapt.
		$rates = $data['rates']
			?? $data['shipmentEstimates']
			?? $data['estimates']
			?? $data['services']
			?? $data['rateOptions']
			?? [];

		// Some responses return a single object rather than an array
		if ( empty( $rates ) && isset( $data['serviceCode'] ) ) {
			$rates = [ $data ];
		}

		if ( empty( $rates ) ) {
			$keys = implode( ', ', array_keys( $data ) );
			$this->log( "Purolator: no rates found. Top-level keys in response: $keys" );
			return;
		}

		$added = 0;
		foreach ( $rates as $rate ) {
			$service_code = $rate['serviceCode'] ?? $rate['service'] ?? $rate['code'] ?? '';
			$service_name = $rate['serviceName'] ?? $rate['serviceDescription'] ?? $rate['name'] ?? $service_code;
			$base_price   = (float) (
				$rate['totalPrice'] ?? $rate['total'] ?? $rate['amount'] ?? $rate['price'] ?? 0
			);

			if ( $base_price <= 0 ) {
				continue;
			}

			$final = $base_price * ( 1 + $markup_pct / 100 );

			$this->add_rate( [
				'id'    => $this->id . '_' . sanitize_key( $service_code ),
				'label' => 'Purolator: ' . ( $service_name ?: $service_code ),
				'cost'  => round( $final, 2 ),
			] );

			$added++;
		}

		$this->log( "Purolator: added $added rate(s)." );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function get_next_business_day() {
		$date = new DateTime( 'now', new DateTimeZone( 'America/Toronto' ) );
		$day  = (int) $date->format( 'N' ); // 1=Mon … 7=Sun
		if ( $day >= 5 ) {
			$date->modify( '+' . ( 8 - $day ) . ' days' );
		} else {
			$date->modify( '+1 day' );
		}
		return $date->format( 'Y-m-d' );
	}

	private function get_package_weight_kg( $package ) {
		$weight_unit  = get_option( 'woocommerce_weight_unit', 'kg' );
		$total_weight = 0;

		foreach ( $package['contents'] as $item ) {
			$total_weight += (float) $item['data']->get_weight() * (int) $item['quantity'];
		}

		if ( $total_weight <= 0 ) {
			$total_weight = 0.5;
		}

		switch ( $weight_unit ) {
			case 'lbs': $total_weight *= 0.453592; break;
			case 'oz':  $total_weight *= 0.0283495; break;
			case 'g':   $total_weight /= 1000; break;
		}

		return round( max( 0.1, $total_weight ), 3 );
	}

	private function get_package_dims_cm( $package ) {
		$dim_unit = get_option( 'woocommerce_dimension_unit', 'cm' );
		$length   = 30;
		$width    = 20;
		$height   = 15;

		foreach ( $package['contents'] as $item ) {
			$product = $item['data'];
			$l       = (float) $product->get_length();
			$w       = (float) $product->get_width();
			$h       = (float) $product->get_height();
			if ( $l > 0 ) $length = max( $length, $l );
			if ( $w > 0 ) $width  = max( $width,  $w );
			if ( $h > 0 ) $height = max( $height, $h );
		}

		$mult = 1;
		switch ( $dim_unit ) {
			case 'mm': $mult = 0.1; break;
			case 'in': $mult = 2.54; break;
			case 'm':  $mult = 100; break;
		}

		return [
			'length' => max( 1, round( $length * $mult, 1 ) ),
			'width'  => max( 1, round( $width  * $mult, 1 ) ),
			'height' => max( 1, round( $height * $mult, 1 ) ),
		];
	}

	private function log( $message ) {
		$logger  = wc_get_logger();
		$context = [ 'source' => 'wclsr-purolator' ];
		$logger->error( $message, $context );
	}
}
