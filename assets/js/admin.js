/**
 * Cambio rápido del estado de producción desde el listado de pedidos y desde
 * el tablero de producción.
 */
( function ( $ ) {
	'use strict';

	var settings = window.ioPosAdmin || {};

	$( document ).on( 'change', '.io-pos-status-select', function () {
		var $select = $( this );
		var orderId = $select.data( 'order-id' );
		var status = $select.val();

		$select.addClass( 'is-saving' ).removeClass( 'is-error' ).prop( 'disabled', true );

		$.post( settings.ajaxUrl, {
			action: 'io_pos_set_production_status',
			nonce: settings.nonce,
			order_id: orderId,
			status: status
		} ).done( function ( response ) {
			if ( ! response || ! response.success ) {
				$select.addClass( 'is-error' );
			}
		} ).fail( function () {
			$select.addClass( 'is-error' );

			window.alert( settings.error );
		} ).always( function () {
			$select.removeClass( 'is-saving' ).prop( 'disabled', false );
		} );
	} );
} )( window.jQuery );
