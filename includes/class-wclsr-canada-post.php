<?php
defined( 'ABSPATH' ) || exit;

class WCLSR_Canada_Post extends WCLSR_Base {

        protected $sections = [
                'General'         => [ 'enabled', 'title' ],
                'API Credentials' => [ 'api_username', 'api_password', 'customer_number' ],
                'Shipping Setup'  => [ 'origin_postal_code', 'sandbox', 'markup' ],
        ];

        public function __construct( $instance_id = 0 ) {
                $this->id                 = 'wclsr_canada_post';
                $this->instance_id        = absint( $instance_id );
                $this->method_title       = __( 'Canada Post (Live Rates)', 'wc-live-shipping-rates' );
                $this->method_description = __( 'Display live shipping rates from Canada Post at checkout.', 'wc-live-shipping-rates' );
                $this->supports           = [ 'shipping-zones', 'instance-settings' ];

                $this->init();
        }

        public function init() {
                $this->init_form_fields();
                $this->init_settings();

                $this->title       = $this->get_option( 'title', 'Canada Post' );
                $this->enabled     = $this->get_option( 'enabled' );

                add_action( 'woocommerce_update_options_shipping_' . $this->id, [ $this, 'process_admin_options' ] );
        }

        public function init_form_fields() {
                $this->instance_form_fields = [
                        'enabled' => [
                                'title'   => __( 'Enable', 'wc-live-shipping-rates' ),
                                'type'    => 'checkbox',
                                'label'   => __( 'Enable Canada Post live rates', 'wc-live-shipping-rates' ),
                                'default' => 'yes',
                        ],
                        'title' => [
                                'title'       => __( 'Method Title', 'wc-live-shipping-rates' ),
                                'type'        => 'text',
                                'description' => __( 'Label shown to customers.', 'wc-live-shipping-rates' ),
                                'default'     => 'Canada Post',
                                'desc_tip'    => true,
                        ],
                        'api_username' => [
                                'title'       => __( 'API Username', 'wc-live-shipping-rates' ),
                                'type'        => 'text',
                                'description' => __( 'Canada Post REST API username (from your developer account).', 'wc-live-shipping-rates' ),
                                'default'     => '',
                                'desc_tip'    => true,
                        ],
                        'api_password' => [
                                'title'       => __( 'API Password', 'wc-live-shipping-rates' ),
                                'type'        => 'password',
                                'description' => __( 'Canada Post REST API password.', 'wc-live-shipping-rates' ),
                                'default'     => '',
                                'desc_tip'    => true,
                        ],
                        'customer_number' => [
                                'title'       => __( 'Customer Number', 'wc-live-shipping-rates' ),
                                'type'        => 'text',
                                'description' => __( 'Your Canada Post customer number.', 'wc-live-shipping-rates' ),
                                'default'     => '',
                                'desc_tip'    => true,
                        ],
                        'origin_postal_code' => [
                                'title'       => __( 'Origin Postal Code', 'wc-live-shipping-rates' ),
                                'type'        => 'text',
                                'description' => __( 'The postal code your shipments originate from (e.g. K1A 0B1).', 'wc-live-shipping-rates' ),
                                'default'     => '',
                                'desc_tip'    => true,
                        ],
                        'sandbox' => [
                                'title'   => __( 'Sandbox / Test Mode', 'wc-live-shipping-rates' ),
                                'type'    => 'checkbox',
                                'label'   => __( 'Use Canada Post sandbox (development API)', 'wc-live-shipping-rates' ),
                                'default' => 'yes',
                        ],
                        'markup' => [
                                'title'       => __( 'Rate Markup (%)', 'wc-live-shipping-rates' ),
                                'type'        => 'number',
                                'description' => __( 'Optional percentage added on top of the carrier rate (e.g. 10 adds 10%).', 'wc-live-shipping-rates' ),
                                'default'     => '0',
                                'desc_tip'    => true,
                                'custom_attributes' => [ 'min' => '0', 'step' => '0.01' ],
                        ],
                ];
        }

        public function calculate_shipping( $package = [] ) {
                $api_username      = $this->get_option( 'api_username' );
                $api_password      = $this->get_option( 'api_password' );
                $customer_number   = $this->get_option( 'customer_number' );
                $origin_postal     = preg_replace( '/\s+/', '', strtoupper( $this->get_option( 'origin_postal_code' ) ) );
                $sandbox           = $this->get_option( 'sandbox' ) === 'yes';
                $markup_pct        = (float) $this->get_option( 'markup', 0 );

                if ( empty( $api_username ) || empty( $api_password ) || empty( $customer_number ) || empty( $origin_postal ) ) {
                        $this->log( 'Canada Post: missing credentials or origin postal code.' );
                        return;
                }

                $destination_postal = preg_replace( '/\s+/', '', strtoupper( $package['destination']['postcode'] ?? '' ) );
                if ( empty( $destination_postal ) ) {
                        return;
                }

                $weight_kg = $this->get_package_weight_kg( $package );
                $dims      = $this->get_package_dims_cm( $package );

                $base_url = $sandbox
                        ? 'https://ct.soa-gw.canadapost.ca/rs/ship/price'
                        : 'https://soa-gw.canadapost.ca/rs/ship/price';

                $xml = $this->build_rate_request_xml( $customer_number, $origin_postal, $destination_postal, $weight_kg, $dims );

                $response = wp_remote_post( $base_url, [
                        'headers' => [
                                'Authorization' => 'Basic ' . base64_encode( $api_username . ':' . $api_password ),
                                'Accept'        => 'application/vnd.cpc.ship.rate-v4+xml',
                                'Content-Type'  => 'application/vnd.cpc.ship.rate-v4+xml',
                                'Accept-language' => 'en-CA',
                        ],
                        'body'    => $xml,
                        'timeout' => 15,
                ] );

                if ( is_wp_error( $response ) ) {
                        $this->log( 'Canada Post API error: ' . $response->get_error_message() );
                        return;
                }

                $code = wp_remote_retrieve_response_code( $response );
                $body = wp_remote_retrieve_body( $response );

                if ( $code !== 200 ) {
                        $this->log( "Canada Post API returned HTTP $code: $body" );
                        return;
                }

                $this->parse_and_add_rates( $body, $markup_pct );
        }

        private function build_rate_request_xml( $customer_number, $origin_postal, $destination_postal, $weight_kg, $dims ) {
                $length = $dims['length'];
                $width  = $dims['width'];
                $height = $dims['height'];

                return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<mailing-scenario xmlns="http://www.canadapost.ca/ws/ship/rate-v4">
  <customer-number>{$customer_number}</customer-number>
  <parcel-characteristics>
    <weight>{$weight_kg}</weight>
    <dimensions>
      <length>{$length}</length>
      <width>{$width}</width>
      <height>{$height}</height>
    </dimensions>
  </parcel-characteristics>
  <origin-postal-code>{$origin_postal}</origin-postal-code>
  <destination>
    <domestic>
      <postal-code>{$destination_postal}</postal-code>
    </domestic>
  </destination>
</mailing-scenario>
XML;
        }

        private function parse_and_add_rates( $xml_body, $markup_pct ) {
                libxml_use_internal_errors( true );
                $xml = simplexml_load_string( $xml_body );
                if ( ! $xml ) {
                        $this->log( 'Canada Post: failed to parse XML response.' );
                        return;
                }

                $xml->registerXPathNamespace( 'cp', 'http://www.canadapost.ca/ws/ship/rate-v4' );
                $services = $xml->xpath( '//cp:price-quote' );

                foreach ( $services as $service ) {
                        $service_code = (string) $service->{'service-code'};
                        $service_name = (string) $service->{'service-name'};
                        $base_price   = (float) $service->{'price-details'}->{'due'};

                        if ( $base_price <= 0 ) continue;

                        $rate = $base_price * ( 1 + $markup_pct / 100 );

                        $this->add_rate( [
                                'id'    => $this->id . '_' . sanitize_key( $service_code ),
                                'label' => 'Canada Post: ' . $service_name,
                                'cost'  => round( $rate, 2 ),
                        ] );
                }
        }

        private function get_package_weight_kg( $package ) {
                $weight_unit = get_option( 'woocommerce_weight_unit', 'kg' );
                $total_weight = 0;

                foreach ( $package['contents'] as $item ) {
                        $product = $item['data'];
                        $weight  = (float) $product->get_weight();
                        $qty     = (int) $item['quantity'];
                        $total_weight += $weight * $qty;
                }

                if ( $total_weight <= 0 ) $total_weight = 0.5;

                if ( $weight_unit === 'lbs' ) {
                        $total_weight = $total_weight * 0.453592;
                } elseif ( $weight_unit === 'oz' ) {
                        $total_weight = $total_weight * 0.0283495;
                } elseif ( $weight_unit === 'g' ) {
                        $total_weight = $total_weight / 1000;
                }

                return round( max( 0.1, $total_weight ), 3 );
        }

        private function get_package_dims_cm( $package ) {
                $dim_unit = get_option( 'woocommerce_dimension_unit', 'cm' );
                $length = 30; $width = 20; $height = 15;

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
                if ( $dim_unit === 'mm' ) $multiplier = 0.1;
                elseif ( $dim_unit === 'in' ) $multiplier = 2.54;
                elseif ( $dim_unit === 'm' )  $multiplier = 100;

                return [
                        'length' => round( $length * $multiplier, 1 ),
                        'width'  => round( $width  * $multiplier, 1 ),
                        'height' => round( $height * $multiplier, 1 ),
                ];
        }

        private function log( $message ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( '[WCLSR] ' . $message );
                }
        }
}
