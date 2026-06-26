<?php
defined( 'ABSPATH' ) || exit;

abstract class WCLSR_Base extends WC_Shipping_Method {

	/**
	 * Define sections as:
	 * [
	 *   'Section Label' => [ 'field_key1', 'field_key2', ... ],
	 * ]
	 * Fields not listed fall into an implicit last section.
	 */
	protected $sections = [];

	/**
	 * Override WooCommerce's default settings renderer with an accordion layout.
	 */
	public function admin_options() {
		$this->enqueue_admin_assets();
		echo '<h2>' . esc_html( $this->method_title );
		wc_back_link( __( 'Return to shipping methods', 'wc-live-shipping-rates' ), admin_url( 'admin.php?page=wc-settings&tab=shipping' ) );
		echo '</h2>';
		echo '<p>' . esc_html( $this->method_description ) . '</p>';
		echo '<table class="form-table">';
		$this->generate_settings_html_with_sections();
		echo '</table>';
	}

	private function generate_settings_html_with_sections() {
		if ( empty( $this->sections ) ) {
			echo $this->generate_settings_html( array_keys( $this->instance_form_fields ) );
			return;
		}

		$all_keys     = array_keys( $this->instance_form_fields );
		$mapped_keys  = array_merge( ...array_values( $this->sections ) );
		$remainder    = array_diff( $all_keys, $mapped_keys );

		foreach ( $this->sections as $label => $keys ) {
			$count = count( $keys );
			$this->render_section_header( $label, $count );
			echo '<tr class="wclsr-section-body"><td colspan="2"><table class="form-table wclsr-inner-table">';
			echo $this->generate_settings_html( $keys );
			echo '</table></td></tr>';
		}

		if ( ! empty( $remainder ) ) {
			echo $this->generate_settings_html( $remainder );
		}
	}

	private function render_section_header( $label, $count ) {
		printf(
			'<tr class="wclsr-section-header">
				<td colspan="2">
					<button type="button" class="wclsr-toggle button-link">
						<span class="wclsr-toggle-icon dashicons dashicons-arrow-right-alt2"></span>
						<strong>%s</strong>
						<span class="wclsr-count">%d %s</span>
					</button>
				</td>
			</tr>',
			esc_html( $label ),
			$count,
			esc_html( _n( 'option', 'options', $count, 'wc-live-shipping-rates' ) )
		);
	}

	private function enqueue_admin_assets() {
		wp_enqueue_style(
			'wclsr-admin',
			WCLSR_URL . 'assets/admin.css',
			[],
			WCLSR_VERSION
		);
		wp_enqueue_script(
			'wclsr-admin',
			WCLSR_URL . 'assets/admin.js',
			[ 'jquery' ],
			WCLSR_VERSION,
			true
		);
	}
}
