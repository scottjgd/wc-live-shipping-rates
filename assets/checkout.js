( function ( $ ) {
	'use strict';

	var CARRIERS = [
		{ key: 'wclsr_canada_post', label: 'Canada Post' },
		{ key: 'wclsr_ups',         label: 'UPS' },
		{ key: 'wclsr_purolator',   label: 'Purolator' },
	];

	function buildAccordions() {
		var $list = $( 'ul#shipping_method, ul.woocommerce-shipping-rates' );
		if ( ! $list.length ) return;

		$list.each( function () {
			var $ul = $( this );

			/* Already converted — rebuild cleanly */
			$ul.find( '.wclsr-carrier-group' ).each( function () {
				$( this ).find( 'li' ).unwrap();
			} );

			CARRIERS.forEach( function ( carrier ) {
				/* Collect all <li> items that belong to this carrier */
				var $items = $ul.find( 'li' ).filter( function () {
					var inputId = $( this ).find( 'input[type="radio"]' ).attr( 'id' ) || '';
					return inputId.indexOf( carrier.key ) === 0;
				} );

				if ( ! $items.length ) return;

				var count = $items.length;
				var hasSelected = $items.filter( function () {
					return $( this ).find( 'input[type="radio"]' ).is( ':checked' );
				} ).length > 0;

				/* Build the group wrapper */
				var $group = $( '<li class="wclsr-carrier-group"></li>' );
				if ( hasSelected ) $group.addClass( 'is-open has-selected' );

				var $toggle = $(
					'<button type="button" class="wclsr-carrier-toggle">' +
						'<span class="wclsr-carrier-name">' + carrier.label + '</span>' +
						'<span class="wclsr-carrier-badge">' + count + ' ' + ( count === 1 ? 'option' : 'options' ) + '</span>' +
						'<span class="wclsr-carrier-arrow">&#8250;</span>' +
					'</button>'
				);

				var $optionsWrap = $( '<ul class="wclsr-carrier-options"></ul>' );
				$items.each( function () {
					$( this ).appendTo( $optionsWrap );
				} );

				$group.append( $toggle ).append( $optionsWrap );
				$ul.append( $group );
			} );
		} );
	}

	/* Toggle open/close */
	$( document ).on( 'click', '.wclsr-carrier-toggle', function () {
		var $group = $( this ).closest( '.wclsr-carrier-group' );
		$group.toggleClass( 'is-open' );
	} );

	/* Mark carrier as having a selection when a rate is chosen */
	$( document ).on( 'change', 'ul#shipping_method input[type="radio"], ul.woocommerce-shipping-rates input[type="radio"]', function () {
		var $radio = $( this );
		$( '.wclsr-carrier-group' ).removeClass( 'has-selected' );
		$radio.closest( '.wclsr-carrier-group' ).addClass( 'has-selected' );
	} );

	/* Run on page load and after WooCommerce refreshes shipping via AJAX */
	$( document ).ready( buildAccordions );
	$( document.body ).on( 'updated_checkout updated_shipping_method', buildAccordions );

} )( jQuery );
