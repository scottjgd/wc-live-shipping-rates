<?php
defined( 'ABSPATH' ) || exit;

/**
 * Purolator Live Rates — ship.purolator.com REST API
 *
 * Credentials (ship.purolator.com → Developer Portal → Manage Apps → your app):
 *   - Client ID     → Okta client_id  (used in token request)
 *   - Client Secret → Okta client_secret (used in token request)
 *   - API Key       → X-Api-Key header  (used on every API call, separate from OAuth)
 *
 * Auth (Okta OAuth2 client_credentials):
 *   POST https://auth.purolator.com/oauth2/aus11x6a58hwZ5OOP5d7/v1/token
 *   Content-Type: application/x-www-form-urlencoded
 *   Body: grant_type=client_credentials&client_id=<client_id>&client_secret=<client_secret>
 *   → { access_token, token_type, expires_in }
 *
 * Estimate:
 *   POST https://api.purolator.com/v1/estimates
 *   Authorization: Bearer <access_token>
 *   X-Api-Key: <api_key>
 *   Content-Type: application/json
 */
class WCLSR_Purolator extends WCLSR_Base {

        const TOKEN_URL    = 'https://auth.purolator.com/oauth2/aus11x6a58hwZ5OOP5d7/v1/token';
        const ESTIMATE_URL = 'https://api.purolator.com/v1/estimates';

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
                        'client_id' => [
                                'title'       => __( 'Client ID', 'wc-live-shipping-rates' ),
                                'type'        => 'text',
                                'description' => __( 'From ship.purolator.com → Developer Portal → Manage Apps → your app → Client ID. Used for OAuth2 authentication.', 'wc-live-shipping-rates' ),
                                'default'     => '',
                                'desc_tip'    => true,
                        ],
                        'client_secret' => [
                                'title'       => __( 'Client Secret', 'wc-live-shipping-rates' ),
                                'type'        => 'password',
                                'description' => __( 'From ship.purolator.com → Developer Portal → Manage Apps → your app → Client Secret. Used for OAuth2 authentication.', 'wc-live-shipping-rates' ),
                                'default'     => '',
                                'desc_tip'    => true,
                        ],
                        'oauth_scope' => [
                                'title'       => __( 'OAuth Scope', 'wc-live-shipping-rates' ),
                                'type'        => 'text',
                                'description' => __( 'OAuth2 scope required by Purolator\'s token server. Check your Purolator developer portal documentation or contact Purolator API support for the correct value. Try "openid" first; the WC log will show the exact error if it is wrong.', 'wc-live-shipping-rates' ),
                                'default'     => 'openid',
                                'desc_tip'    => true,
                        ],
                        'api_key' => [
                                'title'       => __( 'API Key', 'wc-live-shipping-rates' ),
                                'type'        => 'text',
                                'description' => __( 'From ship.purolator.com → Developer Portal → Manage Apps → your app → API Key. Sent as the X-Api-Key header on every API request.', 'wc-live-shipping-rates' ),
                                'default'     => '',
                                'desc_tip'    => true,
                        ],
                        'account_number' => [
                                'title'       => __( 'Account Number', 'wc-live-shipping-rates' ),
                                'type'        => 'text',
                                'description' => __( 'Your Purolator billing account number (zero-padded to 7 digits automatically). Find it under Manage Apps → Test Sender Account.', 'wc-live-shipping-rates' ),
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
                $client_id      = trim( $this->get_option( 'client_id' ) );
                $client_secret  = trim( $this->get_option( 'client_secret' ) );
                $oauth_scope    = trim( $this->get_option( 'oauth_scope', 'openid' ) );
                $api_key        = trim( $this->get_option( 'api_key' ) );
                $account_number = trim( $this->get_option( 'account_number' ) );
                $origin_postal  = preg_replace( '/\s+/', '', strtoupper( $this->get_option( 'origin_postal_code' ) ) );
                $markup_pct     = (float) $this->get_option( 'markup', 0 );

                if ( empty( $client_id ) || empty( $client_secret ) || empty( $api_key ) || empty( $account_number ) || empty( $origin_postal ) ) {
                        $this->log( 'Purolator: missing credentials — check Client ID, Client Secret, API Key, Account Number, and Origin Postal Code in plugin settings.' );
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

                // Step 1 — Okta OAuth2 client_credentials token (cached as WP transient)
                $token = $this->get_bearer_token( $client_id, $client_secret, $oauth_scope );
                if ( ! $token ) {
                        return;
                }

                // Step 2 — Purolator Estimate API
                $this->send_estimate_request(
                        $token, $api_key, $account_number,
                        $origin_postal, $dest_postal,
                        $weight_kg, $dims, $markup_pct
                );
        }

        // -------------------------------------------------------------------------
        // Okta OAuth2 client_credentials token
        // -------------------------------------------------------------------------

        private function get_bearer_token( $client_id, $client_secret, $scope = 'openid' ) {
                $cache_key = 'wclsr_purolator_tok_' . md5( $client_id . $scope );
                $cached    = get_transient( $cache_key );
                if ( $cached ) {
                        $this->log( 'Purolator: using cached auth token.' );
                        return $cached;
                }

                $this->log( 'Purolator: requesting auth token from ' . self::TOKEN_URL . ' (client_id=' . substr( $client_id, 0, 8 ) . '... scope=' . $scope . ')' );

                $body_params = [
                        'grant_type'    => 'client_credentials',
                        'client_id'     => $client_id,
                        'client_secret' => $client_secret,
                ];
                if ( ! empty( $scope ) ) {
                        $body_params['scope'] = $scope;
                }

                $response = wp_remote_post( self::TOKEN_URL, [
                        'headers' => [
                                'Content-Type' => 'application/x-www-form-urlencoded',
                                'Accept'       => 'application/json',
                        ],
                        'body'    => http_build_query( $body_params ),
                        'timeout' => 15,
                ] );

                if ( is_wp_error( $response ) ) {
                        $this->log( 'Purolator: auth HTTP error — ' . $response->get_error_message() );
                        return null;
                }

                $code = wp_remote_retrieve_response_code( $response );
                $body = wp_remote_retrieve_body( $response );

                $this->log( "Purolator: auth response HTTP $code — " . substr( $body, 0, 800 ) );

                if ( $code !== 200 ) {
                        return null;
                }

                $data  = json_decode( $body, true );
                $token = $data['access_token'] ?? '';

                if ( empty( $token ) ) {
                        $this->log( 'Purolator: auth OK but no access_token in response.' );
                        return null;
                }

                $expires_in = (int) ( $data['expires_in'] ?? 3600 );
                set_transient( $cache_key, $token, max( 60, $expires_in - 300 ) );

                $this->log( 'Purolator: auth token obtained (expires_in=' . $expires_in . 's).' );

                return $token;
        }

        // -------------------------------------------------------------------------
        // Estimate request
        // -------------------------------------------------------------------------

        private function send_estimate_request( $token, $api_key, $account_number, $origin_postal, $dest_postal, $weight_kg, $dims, $markup_pct ) {
                $ship_date = $this->get_next_business_day();

                $payload = [
                        'shipmentDate'        => $ship_date,
                        'lineOfBusiness'      => 'Courier',
                        'senderInformation'   => [
                                'address' => [
                                        'postalCode' => $origin_postal,
                                        'country'    => 'CA',
                                ],
                        ],
                        'receiverInformation' => [
                                'address' => [
                                        'postalCode' => $dest_postal,
                                        'country'    => 'CA',
                                ],
                        ],
                        'packageInformation'  => [
                                'totalWeight' => [
                                        'value'      => $weight_kg,
                                        'weightUnit' => 'kg',
                                ],
                                'totalPieces'       => 1,
                                'piecesInformation' => [
                                        [
                                                'weight' => [ 'value' => $weight_kg, 'weightUnit' => 'kg' ],
                                                'length' => [ 'value' => $dims['length'], 'dimensionUnit' => 'cm' ],
                                                'width'  => [ 'value' => $dims['width'],  'dimensionUnit' => 'cm' ],
                                                'height' => [ 'value' => $dims['height'], 'dimensionUnit' => 'cm' ],
                                        ],
                                ],
                        ],
                        'paymentInformation'  => [
                                'paymentType'             => 'Sender',
                                'registeredAccountNumber' => $account_number,
                        ],
                        'pickupInformation'   => [
                                'pickupType' => 'DropOff',
                        ],
                        'showAlternativeServicesIndicator' => true,
                ];

                $this->log( sprintf(
                        'Purolator: estimate → %s | origin=%s dest=%s weight=%.3fkg dims=%sx%sx%scm acct=%s date=%s',
                        self::ESTIMATE_URL, $origin_postal, $dest_postal,
                        $weight_kg, $dims['length'], $dims['width'], $dims['height'],
                        $account_number, $ship_date
                ) );
                $this->log( 'Purolator: payload = ' . wp_json_encode( $payload ) );

                $response = wp_remote_post( self::ESTIMATE_URL, [
                        'headers' => [
                                'Content-Type'  => 'application/json',
                                'Accept'        => 'application/json',
                                'Authorization' => 'Bearer ' . $token,
                                'X-Api-Key'     => $api_key,
                        ],
                        'body'    => wp_json_encode( $payload ),
                        'timeout' => 20,
                ] );

                if ( is_wp_error( $response ) ) {
                        $this->log( 'Purolator: estimate HTTP error — ' . $response->get_error_message() );
                        return;
                }

                $code = wp_remote_retrieve_response_code( $response );
                $body = wp_remote_retrieve_body( $response );

                $this->log( "Purolator: estimate response HTTP $code — " . substr( $body, 0, 1200 ) );

                if ( $code !== 200 ) {
                        return;
                }

                $this->parse_rates( $body, $markup_pct );
        }

        // -------------------------------------------------------------------------
        // Rate parsing
        // -------------------------------------------------------------------------

        private function parse_rates( $body, $markup_pct ) {
                $data = json_decode( $body, true );

                if ( ! is_array( $data ) ) {
                        $this->log( 'Purolator: could not JSON-decode estimate response.' );
                        return;
                }

                // Try known response envelope keys
                $rates = $data['shipmentEstimates']
                        ?? $data['estimates']
                        ?? $data['rates']
                        ?? $data['services']
                        ?? [];

                if ( empty( $rates ) && isset( $data['serviceID'] ) ) {
                        $rates = [ $data ];
                }

                if ( empty( $rates ) ) {
                        $this->log( 'Purolator: no rate array found. Top-level keys: ' . implode( ', ', array_keys( $data ) ) );
                        return;
                }

                $added = 0;
                foreach ( $rates as $rate ) {
                        $service_id   = $rate['serviceID'] ?? $rate['serviceCode'] ?? $rate['service'] ?? '';
                        $service_name = $rate['serviceName'] ?? $rate['serviceDescription'] ?? $rate['name'] ?? $service_id;

                        if ( isset( $rate['totalPrice']['value'] ) ) {
                                $base_price = (float) $rate['totalPrice']['value'];
                        } elseif ( isset( $rate['totalPrice']['amount'] ) ) {
                                $base_price = (float) $rate['totalPrice']['amount'];
                        } else {
                                $base_price = (float) ( $rate['totalPrice'] ?? $rate['total'] ?? $rate['amount'] ?? $rate['price'] ?? 0 );
                        }

                        if ( $base_price <= 0 ) {
                                continue;
                        }

                        $final = $base_price * ( 1 + $markup_pct / 100 );

                        $this->add_rate( [
                                'id'    => $this->id . '_' . sanitize_key( $service_id ),
                                'label' => 'Purolator: ' . ( $service_name ?: $service_id ),
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
                $day  = (int) $date->format( 'N' );
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

                if ( $total_weight <= 0 ) $total_weight = 0.5;

                switch ( $weight_unit ) {
                        case 'lbs': $total_weight *= 0.453592;  break;
                        case 'oz':  $total_weight *= 0.0283495; break;
                        case 'g':   $total_weight /= 1000;      break;
                }

                return round( max( 0.1, $total_weight ), 3 );
        }

        private function get_package_dims_cm( $package ) {
                $dim_unit = get_option( 'woocommerce_dimension_unit', 'cm' );
                $length   = 30; $width = 20; $height = 15;

                foreach ( $package['contents'] as $item ) {
                        $product = $item['data'];
                        $l = (float) $product->get_length();
                        $w = (float) $product->get_width();
                        $h = (float) $product->get_height();
                        if ( $l > 0 ) $length = max( $length, $l );
                        if ( $w > 0 ) $width  = max( $width,  $w );
                        if ( $h > 0 ) $height = max( $height, $h );
                }

                $mult = 1;
                switch ( $dim_unit ) {
                        case 'mm': $mult = 0.1;  break;
                        case 'in': $mult = 2.54; break;
                        case 'm':  $mult = 100;  break;
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
