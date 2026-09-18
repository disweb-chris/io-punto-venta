<?php
/**
 * Puentes con los módulos que ya estaban andando.
 *
 * @package IO\POS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class IO_POS_Compat
 */
class IO_POS_Compat {

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Después del filtro del child theme, que corre en 10.
		add_filter( 'woocommerce_rest_prepare_shop_order_object', array( $this, 'ensure_payment_history' ), 20, 2 );
	}

	/**
	 * Se asegura de que el historial de pagos viaje en la API de pedidos.
	 *
	 * El módulo de finanzas hace la cuenta así: si el pedido trae historial,
	 * suma los cobros; si no, da por cobrado el total. Un pedido con seña sin
	 * historial se contabiliza como cobrado entero, así que acá lo completamos
	 * cuando viene vacío.
	 *
	 * @param WP_REST_Response $response La respuesta.
	 * @param WC_Order         $order    El pedido.
	 *
	 * @return WP_REST_Response
	 */
	public function ensure_payment_history( $response, $order ) {
		if ( ! is_object( $response ) || ! isset( $response->data ) || ! $order instanceof WC_Order ) {
			return $response;
		}

		$current = $response->data['io_pagos_historial'] ?? array();

		if ( is_array( $current ) && $current ) {
			return $response;
		}

		$response->data['io_pagos_historial'] = IO_POS_Payments::get_history( $order );

		return $response;
	}
}
