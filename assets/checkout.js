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

			/* Tear down any previous accordion — move li's back, then remove wrappers */
			$ul.find( '.wclsr-carrier-group' ).each( function () {
				var $group = $( this );
				$group.find( '.wclsr-carrier-options li' ).each( function () {
					$ul.append( $( this ) );
				} );
				$group.remove();
			} );

			CARRIERS.forEach( function ( carrier ) {
				/* Match by the radio VALUE, which WooCommerce sets to the rate ID
				   e.g. "wclsr_canada_post_DOM.EP" — not the element id which is
				   prefixed "shipping_method_0_wclsr_canada_post_..." */
				var $items = $ul.find( 'li' ).filter( function () {
					var val = $( this ).find( 'input[type="radio"]' ).val() || '';
					return val.indexOf( carrier.key ) === 0;
				} );

				if ( ! $items.length ) return;

				var count       = $items.length;
				var hasSelected = $items.filter( function () {
					return $( this ).find( 'input[type="radio"]' ).is( ':checked' );
				} ).length > 0;

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
					$optionsWrap.append( $( this ) );
				} );

				$group.append( $toggle ).append( $optionsWrap );
				$ul.append( $group );
			} );
		} );
	}

	/* Toggle open/close */
	$( document ).on( 'click', '.wclsr-carrier-toggle', function () {
		$( this ).closest( '.wclsr-carrier-group' ).toggleClass( 'is-open' );
	} );

	/* Mark carrier as selected when a rate is chosen */
	$( document ).on( 'change', 'input[type="radio"]', function () {
		var val = $( this ).val() || '';
		$( '.wclsr-carrier-group' ).removeClass( 'has-selected' );
		CARRIERS.forEach( function ( carrier ) {
			if ( val.indexOf( carrier.key ) === 0 ) {
				$( this ).closest( '.wclsr-carrier-group' ).addClass( 'has-selected' );
			}
		}.bind( this ) );
	} );

	/* Run on page load and after WooCommerce refreshes shipping via AJAX */
	$( document ).ready( buildAccordions );
	$( document.body ).on( 'updated_checkout updated_shipping_method wc_update_cart', buildAccordions );

} )( jQuery );
