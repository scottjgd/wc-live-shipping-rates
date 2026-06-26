( function () {
	'use strict';

	var CARRIERS = [
		{ key: 'wclsr_canada_post', label: 'Canada Post' },
		{ key: 'wclsr_ups',         label: 'UPS' },
		{ key: 'wclsr_purolator',   label: 'Purolator' },
	];

	function getCarrier( val ) {
		if ( ! val ) return null;
		for ( var i = 0; i < CARRIERS.length; i++ ) {
			if ( val.indexOf( CARRIERS[ i ].key ) === 0 ) return CARRIERS[ i ];
		}
		return null;
	}

	/* =========================================================================
	   Classic checkout  (WooCommerce shortcode [woocommerce_checkout])
	   ========================================================================= */

	function buildClassicAccordions() {
		var $ = window.jQuery;
		if ( ! $ ) return;

		var $list = $( 'ul#shipping_method, ul.woocommerce-shipping-rates' );
		if ( ! $list.length ) return;

		$list.each( function () {
			var $ul = $( this );

			/* Tear down any previous accordion */
			$ul.find( '.wclsr-carrier-group' ).each( function () {
				$( this ).find( '.wclsr-carrier-options li' ).each( function () {
					$ul.append( $( this ) );
				} );
				$( this ).remove();
			} );

			CARRIERS.forEach( function ( carrier ) {
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

				var $wrap = $( '<ul class="wclsr-carrier-options"></ul>' );
				$items.each( function () { $wrap.append( $( this ) ); } );

				$group.append( $toggle ).append( $wrap );
				$ul.append( $group );
			} );
		} );
	}

	if ( window.jQuery ) {
		jQuery( document ).on( 'click', '.wclsr-carrier-toggle', function () {
			jQuery( this ).closest( '.wclsr-carrier-group' ).toggleClass( 'is-open' );
		} );
		jQuery( document ).on( 'change', 'input[type="radio"]', function () {
			var carrier = getCarrier( jQuery( this ).val() );
			if ( carrier ) {
				jQuery( '.wclsr-carrier-group' ).removeClass( 'has-selected' );
				jQuery( this ).closest( '.wclsr-carrier-group' ).addClass( 'has-selected' );
			}
		} );
		jQuery( document ).ready( buildClassicAccordions );
		jQuery( document.body ).on( 'updated_checkout updated_shipping_method wc_update_cart', buildClassicAccordions );
	}

	/* =========================================================================
	   Block checkout  (WooCommerce Checkout Block — React-based)
	   ========================================================================= */

	var blockDebounce  = null;
	var blockBuilding  = false;

	function buildBlockAccordions() {
		if ( blockBuilding ) return;
		blockBuilding = true;

		try {
			var containers = document.querySelectorAll( '.wc-block-components-radio-control' );

			containers.forEach( function ( container ) {
				var options = Array.from(
					container.querySelectorAll( '.wc-block-components-radio-control__option' )
				);

				/* Only touch containers that have at least one of our rates */
				var hasOurRates = options.some( function ( opt ) {
					var r = opt.querySelector( 'input[type="radio"]' );
					return r && getCarrier( r.value );
				} );
				if ( ! hasOurRates ) return;

				/* Remove previously injected headers and reset hidden state */
				container.querySelectorAll( '.wclsr-block-header' ).forEach( function ( el ) {
					el.remove();
				} );
				options.forEach( function ( opt ) {
					opt.classList.remove( 'wclsr-rate-hidden' );
					delete opt.dataset.wclsrCarrier;
				} );

				/* Tag each option with its carrier key */
				var firstForCarrier = {};
				options.forEach( function ( opt ) {
					var r = opt.querySelector( 'input[type="radio"]' );
					var c = getCarrier( r ? r.value : '' );
					if ( ! c ) return;
					opt.dataset.wclsrCarrier = c.key;
					if ( ! firstForCarrier[ c.key ] ) firstForCarrier[ c.key ] = opt;
				} );

				/* Build one header per carrier, inserted before that carrier's first option */
				CARRIERS.forEach( function ( carrier ) {
					var firstOpt = firstForCarrier[ carrier.key ];
					if ( ! firstOpt ) return;

					var carrierOpts = options.filter( function ( opt ) {
						return opt.dataset.wclsrCarrier === carrier.key;
					} );

					var count       = carrierOpts.length;
					var hasSelected = carrierOpts.some( function ( opt ) {
						var r = opt.querySelector( 'input[type="radio"]' );
						return r && r.checked;
					} );

					var header       = document.createElement( 'div' );
					header.className = 'wclsr-block-header' + ( hasSelected ? ' is-open' : '' );
					header.setAttribute( 'data-carrier', carrier.key );
					header.innerHTML =
						'<span class="wclsr-block-carrier-name">' + carrier.label + '</span>' +
						'<span class="wclsr-block-badge">' + count + ' ' + ( count === 1 ? 'option' : 'options' ) + '</span>' +
						'<span class="wclsr-block-arrow">&#8250;</span>';

					container.insertBefore( header, firstOpt );

					/* Collapse options that belong to this carrier unless one is selected */
					if ( ! hasSelected ) {
						carrierOpts.forEach( function ( opt ) {
							opt.classList.add( 'wclsr-rate-hidden' );
						} );
					}

					header.addEventListener( 'click', function () {
						var opening = ! header.classList.contains( 'is-open' );
						header.classList.toggle( 'is-open', opening );
						carrierOpts.forEach( function ( opt ) {
							opt.classList.toggle( 'wclsr-rate-hidden', ! opening );
						} );
					} );
				} );

				/* When a rate is chosen inside the block, ensure its group opens */
				options.forEach( function ( opt ) {
					var r = opt.querySelector( 'input[type="radio"]' );
					if ( ! r ) return;
					r.addEventListener( 'change', function () {
						var c = getCarrier( r.value );
						if ( ! c ) return;
						var h = container.querySelector( '.wclsr-block-header[data-carrier="' + c.key + '"]' );
						if ( h && ! h.classList.contains( 'is-open' ) ) h.click();
					} );
				} );
			} );
		} finally {
			blockBuilding = false;
		}
	}

	/* MutationObserver — watches for Block checkout re-renders */
	var blockObserver = new MutationObserver( function ( mutations ) {
		if ( blockBuilding ) return;

		/* Ignore mutations caused entirely by our own header injections */
		var allOurs = mutations.every( function ( m ) {
			var added   = Array.from( m.addedNodes );
			var removed = Array.from( m.removedNodes );
			return [ ...added, ...removed ].every( function ( n ) {
				return n.nodeType !== 1 ||
					( n.classList && n.classList.contains( 'wclsr-block-header' ) );
			} );
		} );
		if ( allOurs ) return;

		clearTimeout( blockDebounce );
		blockDebounce = setTimeout( buildBlockAccordions, 200 );
	} );

	/* =========================================================================
	   Boot
	   ========================================================================= */

	function init() {
		buildClassicAccordions();
		buildBlockAccordions();

		/* Watch for Block checkout rendering late or updating */
		blockObserver.observe( document.body, { childList: true, subtree: true } );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

	/* Catch anything that fires after full page load */
	window.addEventListener( 'load', buildBlockAccordions );

} )();
