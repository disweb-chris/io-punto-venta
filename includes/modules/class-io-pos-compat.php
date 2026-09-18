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

	const META_MANUAL_FILES = '_io_pos_drive_manual';

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Después del filtro del child theme, que corre en 10.
		add_filter( 'woocommerce_rest_prepare_shop_order_object', array( $this, 'ensure_payment_history' ), 20, 2 );

		add_action( 'io_pos_job_saved', array( $this, 'sync_drive_files' ) );
	}

	/**
	 * Da por recibidos los archivos cuando el enlace se carga a mano.
	 *
	 * El plugin de subida de archivos avisa «sin archivos todavía — este pedido
	 * no debe pasar a producción» mientras `_io_drive_file_count` esté en cero,
	 * y ese contador solo lo mueve el cliente al subir algo. Si el enlace lo
	 * carga el mostrador, los archivos llegaron por otro lado, así que se marca
	 * el pedido como que ya los tiene.
	 *
	 * Solo toca pedidos cuya carpeta no maneja el plugin de subida, y deshace
	 * la marca si después se borra el enlace.
	 *
	 * @param WC_Order $order El pedido.
	 */
	public function sync_drive_files( $order ) {
		if ( ! $order instanceof WC_Order || ! IO_POS_Settings::is_enabled( 'job_drive_mark_files' ) ) {
			return;
		}

		// La carpeta la creó el plugin de subida: el contador es suyo.
		if ( $order->get_meta( '_io_drive_folder_id' ) ) {
			return;
		}

		$link   = trim( (string) $order->get_meta( '_io_drive_link' ) );
		$count  = (int) $order->get_meta( '_io_drive_file_count' );
		$manual = (int) $order->get_meta( self::META_MANUAL_FILES );

		if ( $link && $count < 1 ) {
			$order->update_meta_data( '_io_drive_file_count', 1 );
			$order->update_meta_data( self::META_MANUAL_FILES, 1 );
			$order->save();

			return;
		}

		if ( ! $link && $manual ) {
			$order->update_meta_data( '_io_drive_file_count', 0 );
			$order->delete_meta_data( self::META_MANUAL_FILES );
			$order->save();
		}
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
