<?php
defined( 'ABSPATH' ) || exit;

class WCLSR_Purolator extends WCLSR_Base {

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
		$api_key        = $this->get_option( 'api_key' );
		$api_password   = $this->get_option( 'api_password' );
		$account_number = $this->get_option( 'account_number' );
		$origin_postal  = preg_replace( '/\s+/', '', strtoupper( $this->get_option( 'origin_postal_code' ) ) );
		$sandbox        = $this->get_option( 'sandbox' ) === 'yes';
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

		$weight_kg = $this->get_package_weight_kg( $package );
		$dims      = $this->get_package_dims_cm( $package );

		$endpoint = $sandbox
			? 'https://devwebservices.purolator.com/EWS/V2/Estimating/EstimatingService.asmx'
			: 'https://webservices.purolator.com/EWS/V2/Estimating/EstimatingService.asmx';

		$soap_xml = $this->build_soap_envelope(
			$api_key, $api_password, $account_number,
			$origin_postal, $dest_postal,
			$weight_kg, $dims
		);

		$response = wp_remote_post( $endpoint, [
			'headers' => [
				'Content-Type' => 'text/xml; charset=utf-8',
				'SOAPAction'   => 'http://purolator.com/pws/service/v2/GetFullEstimate',
			],
			'body'    => $soap_xml,
			'timeout' => 20,
		] );

		if ( is_wp_error( $response ) ) {
			$this->log( 'Purolator: HTTP error — ' . $response->get_error_message() );
			return;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		// Purolator returns HTTP 500 for SOAP faults — parse those too to get the error message.
		if ( $code !== 200 && $code !== 500 ) {
			$this->log( "Purolator: unexpected HTTP $code — $body" );
			return;
		}

		if ( $code === 500 ) {
			// Extract SOAP fault message for the log
			$fault = '';
			if ( preg_match( '/<faultstring[^>]*>([^<]+)<\/faultstring>/i', $body, $m ) ) {
				$fault = $m[1];
			} elseif ( preg_match( '/<soap:Text[^>]*>([^<]+)<\/soap:Text>/i', $body, $m ) ) {
				$fault = $m[1];
			} else {
				$fault = substr( $body, 0, 400 );
			}
			$this->log( "Purolator SOAP fault: $fault" );
			return;
		}

		$this->parse_and_add_rates( $body, $markup_pct );
	}

	/**
	 * Build the Purolator SOAP envelope.
	 *
	 * Two namespaces required by Purolator:
	 *   ser  = http://purolator.com/pws/service/v2   (root request element)
	 *   dat  = http://purolator.com/pws/datatypes/v2 (all data child elements)
	 */
	private function build_soap_envelope( $api_key, $api_password, $account_number, $origin_postal, $dest_postal, $weight_kg, $dims ) {
		$length = $dims['length'];
		$width  = $dims['width'];
		$height = $dims['height'];

		$ship_date = $this->get_next_business_day();

		return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soap:Envelope
  xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"
  xmlns:ser="http://purolator.com/pws/service/v2"
  xmlns:dat="http://purolator.com/pws/datatypes/v2">
  <soap:Header>
    <dat:RequestContext>
      <dat:Version>2.1</dat:Version>
      <dat:Language>en</dat:Language>
      <dat:GroupID>wclsr</dat:GroupID>
      <dat:RequestReference>WCLiveRates</dat:RequestReference>
      <dat:UserToken>{$api_key}</dat:UserToken>
    </dat:RequestContext>
  </soap:Header>
  <soap:Body>
    <ser:GetFullEstimateRequest>
      <dat:Shipment>
        <dat:SenderInformation>
          <dat:Address>
            <dat:PostalCode>{$origin_postal}</dat:PostalCode>
            <dat:Country>CA</dat:Country>
          </dat:Address>
        </dat:SenderInformation>
        <dat:ReceiverInformation>
          <dat:Address>
            <dat:PostalCode>{$dest_postal}</dat:PostalCode>
            <dat:Country>CA</dat:Country>
          </dat:Address>
        </dat:ReceiverInformation>
        <dat:ShipmentDate>{$ship_date}</dat:ShipmentDate>
        <dat:PackageInformation>
          <dat:ServiceID>PurolatorExpress</dat:ServiceID>
          <dat:TotalWeight>
            <dat:Value>{$weight_kg}</dat:Value>
            <dat:WeightUnit>kg</dat:WeightUnit>
          </dat:TotalWeight>
          <dat:TotalPieces>1</dat:TotalPieces>
          <dat:PiecesInformation>
            <dat:Piece>
              <dat:Weight>
                <dat:Value>{$weight_kg}</dat:Value>
                <dat:WeightUnit>kg</dat:WeightUnit>
              </dat:Weight>
              <dat:Length>
                <dat:Value>{$length}</dat:Value>
                <dat:DimensionUnit>cm</dat:DimensionUnit>
              </dat:Length>
              <dat:Width>
                <dat:Value>{$width}</dat:Value>
                <dat:DimensionUnit>cm</dat:DimensionUnit>
              </dat:Width>
              <dat:Height>
                <dat:Value>{$height}</dat:Value>
                <dat:DimensionUnit>cm</dat:DimensionUnit>
              </dat:Height>
            </dat:Piece>
          </dat:PiecesInformation>
        </dat:PackageInformation>
        <dat:PaymentInformation>
          <dat:PaymentType>Sender</dat:PaymentType>
          <dat:RegisteredAccountNumber>{$account_number}</dat:RegisteredAccountNumber>
        </dat:PaymentInformation>
        <dat:PickupInformation>
          <dat:PickupType>DropOff</dat:PickupType>
        </dat:PickupInformation>
      </dat:Shipment>
      <dat:ShowAlternativeServicesIndicator>true</dat:ShowAlternativeServicesIndicator>
    </ser:GetFullEstimateRequest>
  </soap:Body>
</soap:Envelope>
XML;
	}

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

	private function parse_and_add_rates( $soap_body, $markup_pct ) {
		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $soap_body );

		if ( ! $xml ) {
			$this->log( 'Purolator: failed to parse SOAP response XML.' );
			return;
		}

		$xml->registerXPathNamespace( 'soap', 'http://schemas.xmlsoap.org/soap/envelope/' );
		$xml->registerXPathNamespace( 'ser',  'http://purolator.com/pws/service/v2' );
		$xml->registerXPathNamespace( 'dat',  'http://purolator.com/pws/datatypes/v2' );

		// Try both namespaced and un-namespaced paths for robustness
		$shipments = $xml->xpath( '//dat:ShipmentEstimate' );
		if ( empty( $shipments ) ) {
			$shipments = $xml->xpath( '//*[local-name()="ShipmentEstimate"]' );
		}

		if ( empty( $shipments ) ) {
			// Log a portion of the body so we can see what Purolator returned
			$this->log( 'Purolator: no ShipmentEstimate elements found. Response (first 800 chars): ' . substr( $soap_body, 0, 800 ) );
			return;
		}

		foreach ( $shipments as $shipment ) {
			$service_id   = (string) $shipment->xpath( '*[local-name()="ServiceID"]' )[0]   ?? '';
			$service_name = (string) $shipment->xpath( '*[local-name()="ServiceName"]' )[0] ?? '';
			$base_price   = (float) ( $shipment->xpath( '*[local-name()="TotalPrice"]' )[0] ?? 0 );

			if ( $base_price <= 0 ) continue;

			$rate = $base_price * ( 1 + $markup_pct / 100 );

			$this->add_rate( [
				'id'    => $this->id . '_' . sanitize_key( $service_id ),
				'label' => 'Purolator: ' . ( $service_name ?: $service_id ),
				'cost'  => round( $rate, 2 ),
			] );
		}
	}

	// -------------------------------------------------------------------------
	// Package helpers
	// -------------------------------------------------------------------------

	private function get_package_weight_kg( $package ) {
		$weight_unit  = get_option( 'woocommerce_weight_unit', 'kg' );
		$total_weight = 0;

		foreach ( $package['contents'] as $item ) {
			$total_weight += (float) $item['data']->get_weight() * (int) $item['quantity'];
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
		$length   = 30; $width = 20; $height = 15;

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
		$logger  = wc_get_logger();
		$context = [ 'source' => 'wclsr-purolator' ];
		$logger->error( $message, $context );
	}
}
