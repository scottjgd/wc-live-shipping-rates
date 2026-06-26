<?php
defined( 'ABSPATH' ) || exit;

class WCLSR_Purolator extends WCLSR_Base {

        protected $sections = [
                'General'         => [ 'enabled', 'title' ],
                'API Credentials' => [ 'api_key', 'api_password', 'account_number' ],
                'Shipping Setup'  => [ 'origin_postal_code', 'sandbox', 'markup' ],
        ];

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
                                'title'       => __( 'API Key', 'wc-live-shipping-rates' ),
                                'type'        => 'text',
                                'description' => __( 'Purolator API key from the E-Ship Web Services developer portal.', 'wc-live-shipping-rates' ),
                                'default'     => '',
                                'desc_tip'    => true,
                        ],
                        'api_password' => [
                                'title'       => __( 'API Password', 'wc-live-shipping-rates' ),
                                'type'        => 'password',
                                'description' => __( 'Purolator API password.', 'wc-live-shipping-rates' ),
                                'default'     => '',
                                'desc_tip'    => true,
                        ],
                        'account_number' => [
                                'title'       => __( 'Account Number', 'wc-live-shipping-rates' ),
                                'type'        => 'text',
                                'description' => __( 'Your Purolator account number.', 'wc-live-shipping-rates' ),
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
                                'title'   => __( 'Sandbox / Test Mode', 'wc-live-shipping-rates' ),
                                'type'    => 'checkbox',
                                'label'   => __( 'Use Purolator sandbox (development WSDL)', 'wc-live-shipping-rates' ),
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
                $api_key        = $this->get_option( 'api_key' );
                $api_password   = $this->get_option( 'api_password' );
                $account_number = $this->get_option( 'account_number' );
                $origin_postal  = preg_replace( '/\s+/', '', strtoupper( $this->get_option( 'origin_postal_code' ) ) );
                $sandbox        = $this->get_option( 'sandbox' ) === 'yes';
                $markup_pct     = (float) $this->get_option( 'markup', 0 );

                if ( empty( $api_key ) || empty( $api_password ) || empty( $account_number ) || empty( $origin_postal ) ) {
                        $this->log( 'Purolator: missing credentials or origin postal code.' );
                        return;
                }

                $dest_postal = preg_replace( '/\s+/', '', strtoupper( $package['destination']['postcode'] ?? '' ) );
                if ( empty( $dest_postal ) ) return;

                $weight_kg = $this->get_package_weight_kg( $package );
                $dims      = $this->get_package_dims_cm( $package );

                $wsdl = $sandbox
                        ? 'https://devwebservices.purolator.com/EWS/V2/Estimating/EstimatingService.asmx?WSDL'
                        : 'https://webservices.purolator.com/EWS/V2/Estimating/EstimatingService.asmx?WSDL';

                $soap_xml = $this->build_soap_envelope(
                        $api_key, $api_password, $account_number,
                        $origin_postal, $dest_postal,
                        $weight_kg, $dims
                );

                $endpoint = $sandbox
                        ? 'https://devwebservices.purolator.com/EWS/V2/Estimating/EstimatingService.asmx'
                        : 'https://webservices.purolator.com/EWS/V2/Estimating/EstimatingService.asmx';

                $response = wp_remote_post( $endpoint, [
                        'headers' => [
                                'Content-Type' => 'text/xml; charset=utf-8',
                                'SOAPAction'   => 'http://purolator.com/pws/service/v2/GetFullEstimate',
                        ],
                        'body'    => $soap_xml,
                        'timeout' => 20,
                ] );

                if ( is_wp_error( $response ) ) {
                        $this->log( 'Purolator API error: ' . $response->get_error_message() );
                        return;
                }

                $code = wp_remote_retrieve_response_code( $response );
                $body = wp_remote_retrieve_body( $response );

                if ( $code !== 200 ) {
                        $this->log( "Purolator API returned HTTP $code: $body" );
                        return;
                }

                $this->parse_and_add_rates( $body, $markup_pct );
        }

        private function build_soap_envelope( $api_key, $api_password, $account_number, $origin_postal, $dest_postal, $weight_kg, $dims ) {
                $length = $dims['length'];
                $width  = $dims['width'];
                $height = $dims['height'];

                $auth = base64_encode( $api_key . ':' . $api_password );

                return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soap:Envelope
  xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"
  xmlns:v2="http://purolator.com/pws/datatypes/v2">
  <soap:Header>
    <v2:RequestContext>
      <v2:Version>2.1</v2:Version>
      <v2:Language>en</v2:Language>
      <v2:GroupID>wclsr</v2:GroupID>
      <v2:RequestReference>WCLiveRates</v2:RequestReference>
      <v2:UserToken>{$auth}</v2:UserToken>
    </v2:RequestContext>
  </soap:Header>
  <soap:Body>
    <v2:GetFullEstimateRequest>
      <v2:Shipment>
        <v2:SenderInformation>
          <v2:Address>
            <v2:PostalCode>{$origin_postal}</v2:PostalCode>
            <v2:Country>CA</v2:Country>
          </v2:Address>
        </v2:SenderInformation>
        <v2:ReceiverInformation>
          <v2:Address>
            <v2:PostalCode>{$dest_postal}</v2:PostalCode>
            <v2:Country>CA</v2:Country>
          </v2:Address>
        </v2:ReceiverInformation>
        <v2:ShipmentDate>{$this->get_next_business_day()}</v2:ShipmentDate>
        <v2:PackageInformation>
          <v2:ServiceID>PurolatorExpress</v2:ServiceID>
          <v2:TotalWeight>
            <v2:Value>{$weight_kg}</v2:Value>
            <v2:WeightUnit>kg</v2:WeightUnit>
          </v2:TotalWeight>
          <v2:TotalPieces>1</v2:TotalPieces>
          <v2:PiecesInformation>
            <v2:Piece>
              <v2:Weight>
                <v2:Value>{$weight_kg}</v2:Value>
                <v2:WeightUnit>kg</v2:WeightUnit>
              </v2:Weight>
              <v2:Length>
                <v2:Value>{$length}</v2:Value>
                <v2:DimensionUnit>cm</v2:DimensionUnit>
              </v2:Length>
              <v2:Width>
                <v2:Value>{$width}</v2:Value>
                <v2:DimensionUnit>cm</v2:DimensionUnit>
              </v2:Width>
              <v2:Height>
                <v2:Value>{$height}</v2:Value>
                <v2:DimensionUnit>cm</v2:DimensionUnit>
              </v2:Height>
            </v2:Piece>
          </v2:PiecesInformation>
        </v2:PackageInformation>
        <v2:PaymentInformation>
          <v2:PaymentType>Sender</v2:PaymentType>
          <v2:RegisteredAccountNumber>{$account_number}</v2:RegisteredAccountNumber>
        </v2:PaymentInformation>
        <v2:PickupInformation>
          <v2:PickupType>DropOff</v2:PickupType>
        </v2:PickupInformation>
      </v2:Shipment>
      <v2:ShowAlternativeServicesIndicator>true</v2:ShowAlternativeServicesIndicator>
    </v2:GetFullEstimateRequest>
  </soap:Body>
</soap:Envelope>
XML;
        }

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

        private function parse_and_add_rates( $soap_body, $markup_pct ) {
                libxml_use_internal_errors( true );
                $xml = simplexml_load_string( $soap_body );
                if ( ! $xml ) {
                        $this->log( 'Purolator: failed to parse SOAP response.' );
                        return;
                }

                $xml->registerXPathNamespace( 'soap', 'http://schemas.xmlsoap.org/soap/envelope/' );
                $xml->registerXPathNamespace( 'v2', 'http://purolator.com/pws/datatypes/v2' );

                $shipments = $xml->xpath( '//v2:ShipmentEstimate' );

                foreach ( $shipments as $shipment ) {
                        $service_id   = (string) $shipment->{'ServiceID'};
                        $service_name = (string) $shipment->{'ServiceName'};
                        $base_price   = (float) $shipment->{'TotalPrice'};

                        if ( $base_price <= 0 ) continue;

                        $rate = $base_price * ( 1 + $markup_pct / 100 );

                        $this->add_rate( [
                                'id'    => $this->id . '_' . sanitize_key( $service_id ),
                                'label' => 'Purolator: ' . ( $service_name ?: $service_id ),
                                'cost'  => round( $rate, 2 ),
                        ] );
                }
        }

        private function get_package_weight_kg( $package ) {
                $weight_unit  = get_option( 'woocommerce_weight_unit', 'kg' );
                $total_weight = 0;

                foreach ( $package['contents'] as $item ) {
                        $weight = (float) $item['data']->get_weight();
                        $total_weight += $weight * (int) $item['quantity'];
                }

                if ( $total_weight <= 0 ) $total_weight = 0.5;

                switch ( $weight_unit ) {
                        case 'lbs': $total_weight *= 0.453592; break;
                        case 'oz':  $total_weight *= 0.0283495; break;
                        case 'g':   $total_weight /= 1000; break;
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
                switch ( $dim_unit ) {
                        case 'mm': $multiplier = 0.1; break;
                        case 'in': $multiplier = 2.54; break;
                        case 'm':  $multiplier = 100; break;
                }

                return [
                        'length' => max( 1, round( $length * $multiplier, 1 ) ),
                        'width'  => max( 1, round( $width  * $multiplier, 1 ) ),
                        'height' => max( 1, round( $height * $multiplier, 1 ) ),
                ];
        }

        private function log( $message ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( '[WCLSR] ' . $message );
                }
        }
}
