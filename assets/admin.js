jQuery( function ( $ ) {
	'use strict';

	$( document ).on( 'click', '.wclsr-toggle', function () {
		var $btn  = $( this );
		var $body = $btn.closest( 'tr.wclsr-section-header' ).next( 'tr.wclsr-section-body' );

		$btn.toggleClass( 'is-open' );
		$body.toggleClass( 'is-open' );
	} );
} );
