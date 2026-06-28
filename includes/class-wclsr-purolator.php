<?php
defined( 'ABSPATH' ) || exit;

/**
 * Purolator Live Rates — SOAP API v2
 *
 * Uses the Purolator Web Services SOAP API (EstimatingService v2).
 * Authentication: HTTP Basic Auth (API Key : Password) — no OAuth required.
 *
 * Credentials from your Purolator developer account:
 *   API Key      → SOAP username  (from devwebservices.purolator.com developer registration)
 *   API Password → SOAP password  (from devwebservices.purolator.com developer registration)
 *
 * Endpoints:
 *   Development : https://devwebservices.purolator.com/EWS/v2/Estimating/EstimatingService.asmx
 *   Production  : https://webservices.purolator.com/EWS/v2/Estimating/EstimatingService.asmx
 *
 * SOAPAction: "http://purolator.com/pws/service/v2/GetFullEstimate"
 */
class WCLSR_Purolator extends WCLSR_Base {

        const ENDPOINT_DEV  = 'https://devwebservices.purolator.com/EWS/v2/Estimating/EstimatingService.asmx';
        const ENDPOINT_PROD = 'https://webservices.purolator.com/EWS/v2/Estimating/EstimatingService.asmx';
        const SOAP_NS       = 'http://purolator.com/pws/datatypes/v2';
        const SOAP_ACTION   = '"http://purolator.com/pws/service/v2/GetFullEstimate"';

        const SERVICE_NAMES = [
                'PurolatorExpress'                => 'Purolator Express',
                'PurolatorExpress9AM'             => 'Purolator Express 9 AM',
                'PurolatorExpress10:30AM'         => 'Purolator Express 10:30 AM',
                'PurolatorExpressEvening'         => 'Purolator Express Evening',
                'PurolatorExpressEnvelope'        => 'Purolator Express Envelope',
                'PurolatorExpressEnvelope9AM'     => 'Purolator Express Envelope 9 AM',
                'PurolatorExpressEnvelope10:30AM' => 'Purolator Express Envelope 10:30 AM',
                'PurolatorExpressEnvelopeEvening' => 'Purolator Express Envelope Evening',
                'PurolatorExpressPack'            => 'Purolator Express Pack',
                'PurolatorExpressPack9AM'         => 'Purolator Express Pack 9 AM',
                'PurolatorExpressPack10:30AM'     => 'Purolator Express Pack 10:30 AM',
                'PurolatorExpressPackEvening'     => 'Purolator Express Pack Evening',
                'PurolatorExpressBox'             => 'Purolator Express Box',
                'PurolatorExpressBox9AM'          => 'Purolator Express Box 9 AM',
                'PurolatorExpressBox10:30AM'      => 'Purolator Express Box 10:30 AM',
                'PurolatorExpressBoxEvening'      => 'Purolator Express Box Evening',
                'PurolatorGround'                 => 'Purolator Ground',
                'PurolatorGround9AM'              => 'Purolator Ground 9 AM',
                'PurolatorGround10:30AM'          => 'Purolator Ground 10:30 AM',
                'PurolatorGroundEvening'          => 'Purolator Ground Evening',
                'PurolatorGroundRegular'          => 'Purolator Ground Regular',
                'PurolatorQuickShip'              => 'Purolator Quick Ship',
                'PurolatorQuickShipEnvelope'      => 'Purolator Quick Ship Envelope',
                'PurolatorQuickShipPack'          => 'Purolator Quick Ship Pack',
                'PurolatorQuickShipBox'           => 'Purolator Quick Ship Box',
        ];

        public function __construct( $instance_id = 0 ) {
                $this->id                 = 'wclsr_purolator';
                $this->instance_id        = absint( $instance_id );
                $this->method_title       = __( 'Purolator (Live Rates)', 'wc-live-shipping-rates' );
                $this->method_description = __( 'Display live shipping rates from Purolator at checkout using the Purolator Web Services SOAP API v2.', 'wc-live-shipping-rates' );
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
                                'description' => __( 'Label shown to customers at checkout.', 'wc-live-shipping-rates' ),
                        ],
                        'environment' => [
                                'title'       => __( 'Environment', 'wc-live-shipping-rates' ),
                                'type'        => 'select',
                                'description' => __( 'Use Development to test with Purolator\'s sandbox before going live. Switch to Production when ready.', 'wc-live-shipping-rates' ),
                                'default'     => 'development',
                                'desc_tip'    => true,
                                'options'     => [
                                        'development' => __( 'Development (devwebservices.purolator.com)', 'wc-live-shipping-rates' ),
                                        'production'  => __( 'Production (webservices.purolator.com)', 'wc-live-shipping-rates' ),
                                ],
                        ],
                        'api_key' => [
                                'title'       => __( 'API Key', 'wc-live-shipping-rates' ),
                                'type'        => 'text',
                                'description' => __( 'Your Purolator SOAP API Key — used as the HTTP Basic Auth username. Obtained when you register at devwebservices.purolator.com.', 'wc-live-shipping-rates' ),
                                'default'     => '',
                                'desc_tip'    => true,
                        ],
                        'api_password' => [
                                'title'       => __( 'API Password', 'wc-live-shipping-rates' ),
                                'type'        => 'password',
                                'description' => __( 'Your Purolator SOAP API Password (PIN) — used as the HTTP Basic Auth password. Obtained alongside your API Key from devwebservices.purolator.com.', 'wc-live-shipping-rates' ),
                                'default'     => '',
                                'desc_tip'    => true,
                        ],
                        'account_number' => [
                                'title'       => __( 'Account Number', 'wc-live-shipping-rates' ),
                                'type'        => 'text',
                                'description' => __( 'Your Purolator billing account number (automatically zero-padded to 7 digits). Found in your Purolator account or developer portal under Test Sender Account.', 'wc-live-shipping-rates' ),
                                'default'     => '',
                                'desc_tip'    => true,
                        ],
                        'origin_postal_code' => [
                                'title'       => __( 'Origin Postal Code', 'wc-live-shipping-rates' ),
                                'type'        => 'text',
                                'description' => __( 'Canadian postal code your shipments originate from (e.g. K1A0B1). No spaces needed.', 'wc-live-shipping-rates' ),
                                'default'     => '',
                                'desc_tip'    => true,
                        ],
                        'origin_city' => [
                                'title'       => __( 'Origin City', 'wc-live-shipping-rates' ),
                                'type'        => 'text',
                                'description' => __( 'City your shipments originate from (e.g. Ottawa). Required by the Purolator SOAP API.', 'wc-live-shipping-rates' ),
                                'default'     => '',
                                'desc_tip'    => true,
                        ],
                        'origin_province' => [
                                'title'       => __( 'Origin Province', 'wc-live-shipping-rates' ),
                                'type'        => 'text',
                                'description' => __( 'Two-letter province code your shipments originate from (e.g. ON, QC, BC, AB).', 'wc-live-shipping-rates' ),
                                'default'     => 'ON',
                                'desc_tip'    => true,
                        ],
                        'processing_days' => [
                                'title'             => __( 'Processing Time (business days)', 'wc-live-shipping-rates' ),
                                'type'              => 'number',
                                'description'       => __( 'Number of business days you need to prepare an order before it ships. The ship date sent to Purolator is offset by this many business days, so delivery estimates shown at checkout already include your handling time.', 'wc-live-shipping-rates' ),
                                'default'           => '3',
                                'desc_tip'          => true,
                                'custom_attributes' => [ 'min' => '1', 'max' => '30', 'step' => '1' ],
                        ],
                        'markup' => [
                                'title'             => __( 'Rate Markup (%)', 'wc-live-shipping-rates' ),
                                'type'              => 'number',
                                'description'       => __( 'Optional percentage added on top of the Purolator rate shown to customers.', 'wc-live-shipping-rates' ),
                                'default'           => '0',
                                'desc_tip'          => true,
                                'custom_attributes' => [ 'min' => '0', 'step' => '0.01' ],
                        ],
                ];
        }

        // -------------------------------------------------------------------------
        // Main shipping calculation
        // -------------------------------------------------------------------------

        public function calculate_shipping( $package = [] ) {
                $api_key         = trim( $this->get_option( 'api_key' ) );
                $api_password    = trim( $this->get_option( 'api_password' ) );
                $account_number  = trim( $this->get_option( 'account_number' ) );
                $origin_postal   = preg_replace( '/\s+/', '', strtoupper( $this->get_option( 'origin_postal_code' ) ) );
                $origin_city     = trim( $this->get_option( 'origin_city', 'Origin' ) );
                $origin_province = strtoupper( trim( $this->get_option( 'origin_province', 'ON' ) ) );
                $environment     = $this->get_option( 'environment', 'development' );
                $markup_pct      = (float) $this->get_option( 'markup', 0 );

                if ( empty( $api_key ) || empty( $api_password ) || empty( $account_number ) || empty( $origin_postal ) ) {
                        $this->log( 'Purolator: missing credentials — fill in API Key, API Password, Account Number, and Origin Postal Code in the plugin settings.', 'warning' );
                        return;
                }

                $dest_postal   = preg_replace( '/\s+/', '', strtoupper( $package['destination']['postcode'] ?? '' ) );
                $dest_city     = trim( $package['destination']['city'] ?? '' );
                $dest_province = strtoupper( trim( $package['destination']['state'] ?? '' ) );
                $dest_country  = strtoupper( trim( $package['destination']['country'] ?? 'CA' ) );

                if ( empty( $dest_postal ) ) {
                        $this->log( 'Purolator: no destination postal code — cannot calculate rate.', 'warning' );
                        return;
                }

                // Purolator SOAP requires city/province — use safe defaults if checkout hasn't captured them yet.
                if ( empty( $dest_city ) )     $dest_city     = 'City';
                if ( empty( $dest_province ) ) $dest_province = 'ON';
                if ( empty( $dest_country ) )  $dest_country  = 'CA';
                if ( empty( $origin_city ) )   $origin_city   = 'Origin';

                // Zero-pad account number to 7 digits.
                $account_number = str_pad( ltrim( $account_number, '0' ) ?: '0', 7, '0', STR_PAD_LEFT );

                $weight_kg       = $this->get_package_weight_kg( $package );
                $dims            = $this->get_package_dims_cm( $package );
                $processing_days = max( 1, (int) $this->get_option( 'processing_days', 3 ) );
                $ship_date       = $this->get_ship_date( $processing_days );
                $endpoint        = ( $environment === 'production' ) ? self::ENDPOINT_PROD : self::ENDPOINT_DEV;

                $this->log( sprintf(
                        'Purolator SOAP (%s): origin=%s %s/%s → dest=%s %s/%s | weight=%.3fkg dims=%sx%sx%scm acct=%s date=%s',
                        $environment, $origin_postal, $origin_city, $origin_province,
                        $dest_postal, $dest_city, $dest_province,
                        $weight_kg, $dims['length'], $dims['width'], $dims['height'],
                        $account_number, $ship_date
                ), 'debug' );

                $soap_xml = $this->build_soap_xml(
                        $origin_postal, $origin_city, $origin_province,
                        $dest_postal, $dest_city, $dest_province, $dest_country,
                        $weight_kg, $dims, $account_number, $ship_date
                );

                $response = wp_remote_post( $endpoint, [
                        'headers' => [
                                'Content-Type'  => 'text/xml; charset=utf-8',
                                'SOAPAction'    => self::SOAP_ACTION,
                                'Authorization' => 'Basic ' . base64_encode( $api_key . ':' . $api_password ),
                        ],
                        'body'    => $soap_xml,
                        'timeout' => 20,
                ] );

                if ( is_wp_error( $response ) ) {
                        $this->log( 'Purolator SOAP: HTTP error — ' . $response->get_error_message(), 'error' );
                        return;
                }

                $code = wp_remote_retrieve_response_code( $response );
                $body = wp_remote_retrieve_body( $response );

                $this->log( "Purolator SOAP: HTTP $code — " . substr( $body, 0, 2000 ), 'debug' );

                if ( $code !== 200 ) {
                        return;
                }

                $this->parse_soap_response( $body, $markup_pct );
        }

        // -------------------------------------------------------------------------
        // SOAP request builder
        // -------------------------------------------------------------------------

        private function build_soap_xml(
                $origin_postal, $origin_city, $origin_province,
                $dest_postal, $dest_city, $dest_province, $dest_country,
                $weight_kg, $dims, $account_number, $ship_date
        ) {
                $w  = number_format( $weight_kg, 3, '.', '' );
                $l  = number_format( $dims['length'], 1, '.', '' );
                $wi = number_format( $dims['width'],  1, '.', '' );
                $h  = number_format( $dims['height'], 1, '.', '' );

                $e = static function( $v ) { return htmlspecialchars( (string) $v, ENT_XML1, 'UTF-8' ); };

                return <<<XML
<?xml version="1.0" encoding="utf-8"?>
<SOAP-ENV:Envelope
    xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:ns="http://purolator.com/pws/datatypes/v2">
  <SOAP-ENV:Header>
    <ns:RequestContext>
      <ns:Version>2.0</ns:Version>
      <ns:Language>en</ns:Language>
      <ns:GroupID>wclsr</ns:GroupID>
      <ns:RequestReference>WooCommerce Checkout</ns:RequestReference>
    </ns:RequestContext>
  </SOAP-ENV:Header>
  <SOAP-ENV:Body>
    <ns:GetFullEstimateRequest>
      <ns:Shipment>
        <ns:SenderInformation>
          <ns:Address>
            <ns:Name>Shipper</ns:Name>
            <ns:StreetNumber>1</ns:StreetNumber>
            <ns:StreetName>Origin St</ns:StreetName>
            <ns:City>{$e($origin_city)}</ns:City>
            <ns:Province>{$e($origin_province)}</ns:Province>
            <ns:Country>CA</ns:Country>
            <ns:PostalCode>{$e($origin_postal)}</ns:PostalCode>
            <ns:PhoneNumber>
              <ns:CountryCode>1</ns:CountryCode>
              <ns:AreaCode>613</ns:AreaCode>
              <ns:Phone>5550000</ns:Phone>
            </ns:PhoneNumber>
          </ns:Address>
        </ns:SenderInformation>
        <ns:ReceiverInformation>
          <ns:Address>
            <ns:Name>Receiver</ns:Name>
            <ns:StreetNumber>1</ns:StreetNumber>
            <ns:StreetName>Destination St</ns:StreetName>
            <ns:City>{$e($dest_city)}</ns:City>
            <ns:Province>{$e($dest_province)}</ns:Province>
            <ns:Country>{$e($dest_country)}</ns:Country>
            <ns:PostalCode>{$e($dest_postal)}</ns:PostalCode>
            <ns:PhoneNumber>
              <ns:CountryCode>1</ns:CountryCode>
              <ns:AreaCode>416</ns:AreaCode>
              <ns:Phone>5550000</ns:Phone>
            </ns:PhoneNumber>
          </ns:Address>
        </ns:ReceiverInformation>
        <ns:ShipmentDate>{$e($ship_date)}</ns:ShipmentDate>
        <ns:PackageInformation>
          <ns:ServiceID>PurolatorExpress</ns:ServiceID>
          <ns:TotalWeight>
            <ns:Value>{$w}</ns:Value>
            <ns:WeightUnit>kg</ns:WeightUnit>
          </ns:TotalWeight>
          <ns:TotalPieces>1</ns:TotalPieces>
          <ns:PiecesInformation>
            <ns:Piece>
              <ns:Weight>
                <ns:Value>{$w}</ns:Value>
                <ns:WeightUnit>kg</ns:WeightUnit>
              </ns:Weight>
              <ns:Length>
                <ns:Value>{$l}</ns:Value>
                <ns:DimensionUnit>cm</ns:DimensionUnit>
              </ns:Length>
              <ns:Width>
                <ns:Value>{$wi}</ns:Value>
                <ns:DimensionUnit>cm</ns:DimensionUnit>
              </ns:Width>
              <ns:Height>
                <ns:Value>{$h}</ns:Value>
                <ns:DimensionUnit>cm</ns:DimensionUnit>
              </ns:Height>
            </ns:Piece>
          </ns:PiecesInformation>
        </ns:PackageInformation>
        <ns:PaymentInformation>
          <ns:PaymentType>Sender</ns:PaymentType>
          <ns:RegisteredAccountNumber>{$e($account_number)}</ns:RegisteredAccountNumber>
        </ns:PaymentInformation>
        <ns:PickupInformation>
          <ns:PickupType>DropOff</ns:PickupType>
        </ns:PickupInformation>
      </ns:Shipment>
      <ns:ShowAlternativeServicesIndicator>true</ns:ShowAlternativeServicesIndicator>
    </ns:GetFullEstimateRequest>
  </SOAP-ENV:Body>
</SOAP-ENV:Envelope>
XML;
        }

        // -------------------------------------------------------------------------
        // SOAP response parser
        // -------------------------------------------------------------------------

        private function parse_soap_response( $xml_body, $markup_pct ) {
                // Strip UTF-8 BOM if present (causes simplexml/DOM parse failures).
                if ( substr( $xml_body, 0, 3 ) === "\xEF\xBB\xBF" ) {
                        $xml_body = substr( $xml_body, 3 );
                }

                $prev_errors = libxml_use_internal_errors( true );
                $doc = new DOMDocument();
                $loaded = $doc->loadXML( $xml_body, LIBXML_NONET | LIBXML_COMPACT );

                // Always capture and log libxml errors before clearing.
                $lib_errors = libxml_get_errors();
                libxml_clear_errors();
                libxml_use_internal_errors( $prev_errors );

                if ( ! $loaded ) {
                        $msgs = array_map( static function ( $e ) {
                                return trim( $e->message ) . ' (line ' . $e->line . ')';
                        }, $lib_errors );
                        $this->log( 'Purolator SOAP: DOMDocument parse failed — ' . implode( ' | ', $msgs ), 'error' );
                        return;
                }

                if ( ! empty( $lib_errors ) ) {
                        $msgs = array_map( static function ( $e ) {
                                return trim( $e->message );
                        }, $lib_errors );
                        $this->log( 'Purolator SOAP: libxml warnings — ' . implode( ' | ', $msgs ), 'warning' );
                }

                $xpath = new DOMXPath( $doc );
                $xpath->registerNamespace( 'env', 'http://schemas.xmlsoap.org/soap/envelope/' );
                $xpath->registerNamespace( 'pws', self::SOAP_NS );

                // Check for SOAP faults first.
                $faults = $xpath->query( '//env:Fault' );
                if ( $faults && $faults->length > 0 ) {
                        $f = $faults->item( 0 );
                        $fs = $xpath->query( 'faultstring', $f );
                        $fc = $xpath->query( 'faultcode', $f );
                        $msg = ( $fs && $fs->length ) ? $fs->item(0)->nodeValue
                             : ( ( $fc && $fc->length ) ? $fc->item(0)->nodeValue : 'unknown' );
                        $this->log( 'Purolator SOAP: SOAP fault — ' . $msg, 'error' );
                        return;
                }

                // Log any API-level errors.
                $api_errors = $xpath->query( '//pws:Error' );
                if ( $api_errors && $api_errors->length > 0 ) {
                        foreach ( $api_errors as $err ) {
                                $code = $xpath->query( 'pws:Code', $err );
                                $desc = $xpath->query( 'pws:Description', $err );
                                $this->log( 'Purolator SOAP: API error — Code='
                                        . ( ( $code && $code->length ) ? $code->item(0)->nodeValue : '?' )
                                        . ' | '
                                        . ( ( $desc && $desc->length ) ? $desc->item(0)->nodeValue : '?' ),
                                        'error'
                                );
                        }
                }

                // Extract ShipmentEstimate nodes.
                $estimates = $xpath->query( '//pws:ShipmentEstimate' );

                if ( ! $estimates || $estimates->length === 0 ) {
                        // Try without namespace in case the response uses default namespace only.
                        $estimates = $xpath->query( '//*[local-name()="ShipmentEstimate"]' );
                }

                if ( ! $estimates || $estimates->length === 0 ) {
                        $this->log( 'Purolator SOAP: no ShipmentEstimate elements found. Full response (first 3000 chars): '
                                . substr( $xml_body, 0, 3000 ), 'error' );
                        return;
                }

                $added = 0;
                foreach ( $estimates as $est ) {
                        $get = static function ( $name ) use ( $xpath, $est ) {
                                // Try namespace-aware first, then local-name fallback.
                                $nl = $xpath->query( 'pws:' . $name, $est );
                                if ( ! $nl || ! $nl->length ) {
                                        $nl = $xpath->query( '*[local-name()="' . $name . '"]', $est );
                                }
                                return ( $nl && $nl->length ) ? $nl->item(0)->nodeValue : '';
                        };

                        $service_id  = $get( 'ServiceID' );
                        $total_price = (float) $get( 'TotalPrice' );
                        $delivery    = $get( 'ExpectedDeliveryDate' );
                        $transit     = (int) $get( 'EstimatedTransitDays' );

                        if ( empty( $service_id ) || $total_price <= 0 ) {
                                continue;
                        }

                        $label = $this->format_service_label( $service_id, $delivery, $transit );
                        $cost  = round( $total_price * ( 1 + $markup_pct / 100 ), 2 );

                        $this->add_rate( [
                                'id'    => $this->id . '_' . sanitize_key( $service_id ),
                                'label' => $label,
                                'cost'  => $cost,
                        ] );

                        $added++;
                        $this->log( sprintf( 'Purolator SOAP: rate added — %s $%.2f (delivery: %s transit: %d days)',
                                $service_id, $cost, $delivery ?: 'n/a', $transit ), 'debug' );
                }

                if ( $added === 0 ) {
                        $this->log( 'Purolator SOAP: response parsed but no valid rates found.', 'error' );
                } else {
                        $this->log( "Purolator SOAP: $added rate(s) added.", 'debug' );
                }
        }

        // -------------------------------------------------------------------------
        // Helpers
        // -------------------------------------------------------------------------

        private function format_service_label( $service_id, $delivery_date, $transit_days ) {
                $name = self::SERVICE_NAMES[ $service_id ]
                        ?? preg_replace( '/(?<=[a-z])(?=[A-Z0-9])/', ' ', $service_id );

                $suffix = '';
                if ( ! empty( $delivery_date ) && preg_match( '/^\d{4}-\d{2}-\d{2}/', $delivery_date ) ) {
                        $suffix = ' — ' . date_i18n( get_option( 'date_format', 'Y-m-d' ), strtotime( $delivery_date ) );
                } elseif ( $transit_days > 0 ) {
                        $suffix = ' — ' . sprintf(
                                _n( '%d business day', '%d business days', $transit_days, 'wc-live-shipping-rates' ),
                                $transit_days
                        );
                }

                return $name . $suffix;
        }

        private function get_ship_date( $business_days = 1 ) {
                $date  = new DateTime( 'now', new DateTimeZone( 'America/Toronto' ) );
                $added = 0;
                while ( $added < $business_days ) {
                        $date->modify( '+1 day' );
                        $dow = (int) $date->format( 'N' ); // 1=Mon … 7=Sun
                        if ( $dow < 6 ) { // Mon–Fri only
                                $added++;
                        }
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

        private function log( $message, $level = 'debug' ) {
                $logger  = wc_get_logger();
                $context = [ 'source' => 'wclsr-purolator' ];
                $logger->$level( $message, $context );
        }
}
