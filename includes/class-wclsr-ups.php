<?php
defined( 'ABSPATH' ) || exit;

class WCLSR_UPS extends WCLSR_Base {

        protected $sections = [
                'General'         => [ 'enabled', 'title' ],
                'API Credentials' => [ 'client_id', 'client_secret', 'account_number' ],
                'Shipping Setup'  => [ 'origin_postal_code', 'origin_country', 'sandbox', 'markup' ],
        ];

        public function __construct( $instance_id = 0 ) {
                $this->id                 = 'wclsr_ups';
                $this->instance_id        = absint( $instance_id );
                $this->method_title       = __( 'UPS (Live Rates)', 'wc-live-shipping-rates' );
                $this->method_description = __( 'Display live shipping rates from UPS at checkout.', 'wc-live-shipping-rates' );
                $this->supports           = [ 'shipping-zones', 'instance-settings' ];

                $this->init();
        }

        public function init() {
                $this->init_form_fields();
                $this->init_settings();

                $this->title   = $this->get_option( 'title', 'UPS' );
                $this->enabled = $this->get_option( 'enabled' );

                add_action( 'woocommerce_update_options_shipping_' . $this->id, [ $this, 'process_admin_options' ] );
        }

        public function init_form_fields() {
                $this->instance_form_fields = [
                        'enabled' => [
                                'title'   => __( 'Enable', 'wc-live-shipping-rates' ),
                                'type'    => 'checkbox',
                                'label'   => __( 'Enable UPS live rates', 'wc-live-shipping-rates' ),
                                'default' => 'yes',
                        ],
                        'title' => [
                                'title'    => __( 'Method Title', 'wc-live-shipping-rates' ),
                                'type'     => 'text',
                                'default'  => 'UPS',
                                'desc_tip' => true,
                                'description' => __( 'Label shown to customers.', 'wc-live-shipping-rates' ),
                        ],
                        'client_id' => [
                                'title'       => __( 'Client ID', 'wc-live-shipping-rates' ),
                                'type'        => 'text',
                                'description' => __( 'UPS Developer API Client ID (OAuth 2.0).', 'wc-live-shipping-rates' ),
                                'default'     => '',
                                'desc_tip'    => true,
                        ],
                        'client_secret' => [
                                'title'       => __( 'Client Secret', 'wc-live-shipping-rates' ),
                                'type'        => 'password',
                                'description' => __( 'UPS Developer API Client Secret (OAuth 2.0).', 'wc-live-shipping-rates' ),
                                'default'     => '',
                                'desc_tip'    => true,
                        ],
                        'account_number' => [
                                'title'       => __( 'Shipper Account Number', 'wc-live-shipping-rates' ),
                                'type'        => 'text',
                                'description' => __( 'Your UPS account number (6-character alphanumeric).', 'wc-live-shipping-rates' ),
                                'default'     => '',
                                'desc_tip'    => true,
                        ],
                        'origin_postal_code' => [
                                'title'       => __( 'Origin Postal Code', 'wc-live-shipping-rates' ),
                                'type'        => 'text',
                                'description' => __( 'Postal/ZIP code your shipments originate from.', 'wc-live-shipping-rates' ),
                                'default'     => '',
                                'desc_tip'    => true,
                        ],
                        'origin_country' => [
                                'title'       => __( 'Origin Country Code', 'wc-live-shipping-rates' ),
                                'type'        => 'text',
                                'description' => __( 'Two-letter ISO country code (e.g. CA, US).', 'wc-live-shipping-rates' ),
                                'default'     => 'CA',
                                'desc_tip'    => true,
                        ],
                        'sandbox' => [
                                'title'   => __( 'Sandbox / Test Mode', 'wc-live-shipping-rates' ),
                                'type'    => 'checkbox',
                                'label'   => __( 'Use UPS Customer Integration Environment (CIE)', 'wc-live-shipping-rates' ),
                                'default' => 'yes',
                        ],
                        'markup' => [
                                'title'       => __( 'Rate Markup (%)', 'wc-live-shipping-rates' ),
                                'type'        => 'number',
                                'description' => __( 'Optional percentage added on top of the carrier rate.', 'wc-live-shipping-rates' ),
                                'default'     => '0',
                                'desc_tip'    => true,
                                'custom_attributes' => [ 'min' => '0', 'step' => '0.01' ],
                        ],
                ];
        }

        public function calculate_shipping( $package = [] ) {
                $client_id      = $this->get_option( 'client_id' );
                $client_secret  = $this->get_option( 'client_secret' );
                $account_number = $this->get_option( 'account_number' );
                $origin_postal  = strtoupper( trim( $this->get_option( 'origin_postal_code' ) ) );
                $origin_country = strtoupper( trim( $this->get_option( 'origin_country', 'CA' ) ) );
                $sandbox        = $this->get_option( 'sandbox' ) === 'yes';
                $markup_pct     = (float) $this->get_option( 'markup', 0 );

                if ( empty( $client_id ) || empty( $client_secret ) || empty( $account_number ) || empty( $origin_postal ) ) {
                        $this->log( 'UPS: missing credentials or origin postal code.' );
                        return;
                }

                $dest_postal  = strtoupper( trim( $package['destination']['postcode'] ?? '' ) );
                $dest_country = strtoupper( trim( $package['destination']['country'] ?? 'CA' ) );
                if ( empty( $dest_postal ) ) return;

                $token = $this->get_oauth_token( $client_id, $client_secret, $sandbox );
                if ( ! $token ) {
                        $this->log( 'UPS: failed to obtain OAuth token.' );
                        return;
                }

                $weight_lbs = $this->get_package_weight_lbs( $package );
                $dims       = $this->get_package_dims_in( $package );

                $base_url = $sandbox
                        ? 'https://wwwcie.ups.com/api/rating/v2409/Shop'
                        : 'https://onlinetools.ups.com/api/rating/v2409/Shop';

                $payload = $this->build_rate_request( $account_number, $origin_postal, $origin_country, $dest_postal, $dest_country, $weight_lbs, $dims );

                $response = wp_remote_post( $base_url, [
                        'headers' => [
                                'Authorization' => 'Bearer ' . $token,
                                'Content-Type'  => 'application/json',
                                'transId'       => uniqid( 'wclsr_' ),
                                'transactionSrc'=> 'WCLiveShippingRates',
                        ],
                        'body'    => wp_json_encode( $payload ),
                        'timeout' => 20,
                ] );

                if ( is_wp_error( $response ) ) {
                        $this->log( 'UPS API error: ' . $response->get_error_message() );
                        return;
                }

                $code = wp_remote_retrieve_response_code( $response );
                $body = wp_remote_retrieve_body( $response );

                if ( $code !== 200 ) {
                        $this->log( "UPS API returned HTTP $code: $body" );
                        return;
                }

                $data = json_decode( $body, true );
                $this->parse_and_add_rates( $data, $markup_pct );
        }

        private function get_oauth_token( $client_id, $client_secret, $sandbox ) {
                $cache_key = 'wclsr_ups_token_' . md5( $client_id );
                $cached    = get_transient( $cache_key );
                if ( $cached ) return $cached;

                $token_url = $sandbox
                        ? 'https://wwwcie.ups.com/security/v1/oauth/token'
                        : 'https://onlinetools.ups.com/security/v1/oauth/token';

                $response = wp_remote_post( $token_url, [
                        'headers' => [
                                'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $client_secret ),
                                'Content-Type'  => 'application/x-www-form-urlencoded',
                        ],
                        'body'    => 'grant_type=client_credentials',
                        'timeout' => 15,
                ] );

                if ( is_wp_error( $response ) ) return null;
                $data = json_decode( wp_remote_retrieve_body( $response ), true );

                if ( empty( $data['access_token'] ) ) return null;

                $expires = isset( $data['expires_in'] ) ? (int) $data['expires_in'] - 60 : 3540;
                set_transient( $cache_key, $data['access_token'], $expires );

                return $data['access_token'];
        }

        private function build_rate_request( $account_number, $origin_postal, $origin_country, $dest_postal, $dest_country, $weight_lbs, $dims ) {
                return [
                        'RateRequest' => [
                                'Request' => [
                                        'SubVersion'         => '1703',
                                        'RequestOption'      => 'Shop',
                                        'TransactionReference' => [ 'CustomerContext' => 'wclsr' ],
                                ],
                                'Shipment' => [
                                        'Shipper' => [
                                                'Name'          => get_bloginfo( 'name' ),
                                                'ShipperNumber' => $account_number,
                                                'Address'       => [
                                                        'PostalCode'  => $origin_postal,
                                                        'CountryCode' => $origin_country,
                                                ],
                                        ],
                                        'ShipTo' => [
                                                'Name'    => 'Customer',
                                                'Address' => [
                                                        'PostalCode'  => $dest_postal,
                                                        'CountryCode' => $dest_country,
                                                ],
                                        ],
                                        'ShipFrom' => [
                                                'Name'    => get_bloginfo( 'name' ),
                                                'Address' => [
                                                        'PostalCode'  => $origin_postal,
                                                        'CountryCode' => $origin_country,
                                                ],
                                        ],
                                        'Package' => [
                                                'PackagingType' => [ 'Code' => '02', 'Description' => 'Customer Supplied Package' ],
                                                'Dimensions'    => [
                                                        'UnitOfMeasurement' => [ 'Code' => 'IN' ],
                                                        'Length' => (string) $dims['length'],
                                                        'Width'  => (string) $dims['width'],
                                                        'Height' => (string) $dims['height'],
                                                ],
                                                'PackageWeight' => [
                                                        'UnitOfMeasurement' => [ 'Code' => 'LBS' ],
                                                        'Weight' => (string) $weight_lbs,
                                                ],
                                        ],
                                ],
                        ],
                ];
        }

        private function parse_and_add_rates( $data, $markup_pct ) {
                $rated_shipments = $data['RateResponse']['RatedShipment'] ?? [];

                $service_names = [
                        '01' => 'UPS Next Day Air',
                        '02' => 'UPS 2nd Day Air',
                        '03' => 'UPS Ground',
                        '07' => 'UPS Worldwide Express',
                        '08' => 'UPS Worldwide Expedited',
                        '11' => 'UPS Standard',
                        '12' => 'UPS 3 Day Select',
                        '13' => 'UPS Next Day Air Saver',
                        '14' => 'UPS Next Day Air Early',
                        '54' => 'UPS Worldwide Express Plus',
                        '59' => 'UPS 2nd Day Air AM',
                        '65' => 'UPS Worldwide Saver',
                        '70' => 'UPS Access Point Economy',
                ];

                foreach ( $rated_shipments as $shipment ) {
                        $service_code = $shipment['Service']['Code'] ?? '';
                        $service_name = $service_names[ $service_code ] ?? 'UPS Service ' . $service_code;
                        $currency     = $shipment['TotalCharges']['CurrencyCode'] ?? 'CAD';
                        $base_price   = (float) ( $shipment['TotalCharges']['MonetaryValue'] ?? 0 );

                        if ( $base_price <= 0 ) continue;

                        $rate = $base_price * ( 1 + $markup_pct / 100 );

                        $this->add_rate( [
                                'id'    => $this->id . '_' . $service_code,
                                'label' => $service_name,
                                'cost'  => round( $rate, 2 ),
                        ] );
                }
        }

        private function get_package_weight_lbs( $package ) {
                $weight_unit  = get_option( 'woocommerce_weight_unit', 'kg' );
                $total_weight = 0;

                foreach ( $package['contents'] as $item ) {
                        $weight = (float) $item['data']->get_weight();
                        $total_weight += $weight * (int) $item['quantity'];
                }

                if ( $total_weight <= 0 ) $total_weight = 1;

                switch ( $weight_unit ) {
                        case 'kg': $total_weight *= 2.20462; break;
                        case 'g':  $total_weight *= 0.00220462; break;
                        case 'oz': $total_weight *= 0.0625; break;
                }

                return round( max( 0.1, $total_weight ), 2 );
        }

        private function get_package_dims_in( $package ) {
                $dim_unit = get_option( 'woocommerce_dimension_unit', 'cm' );
                $length = 12; $width = 8; $height = 6;

                foreach ( $package['contents'] as $item ) {
                        $product = $item['data'];
                        $l = (float) $product->get_length();
                        $w = (float) $product->get_width();
                        $h = (float) $product->get_height();
                        if ( $l > 0 ) $length = max( $length, $l );
                        if ( $w > 0 ) $width  = max( $width, $w );
                        if ( $h > 0 ) $height = max( $height, $h );
                }

                $multiplier = 1;
                switch ( $dim_unit ) {
                        case 'cm': $multiplier = 0.393701; break;
                        case 'mm': $multiplier = 0.0393701; break;
                        case 'm':  $multiplier = 39.3701; break;
                }

                return [
                        'length' => max( 1, round( $length * $multiplier ) ),
                        'width'  => max( 1, round( $width  * $multiplier ) ),
                        'height' => max( 1, round( $height * $multiplier ) ),
                ];
        }

        private function log( $message ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( '[WCLSR] ' . $message );
                }
        }
}
