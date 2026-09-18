<?php
/**
 * Correos de WooCommerce en las ventas del mostrador.
 *
 * Una venta de mostrador ya se lleva su comprobante impreso: mandar además el
 * mail de "pedido recibido" al cliente y el de "pedido nuevo" a la tienda es
 * ruido. Se puede volver a activar desde los ajustes.
 *
 * @package IO\POS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class IO_POS_Emails
 */
class IO_POS_Emails {

	/**
	 * Correos que se silencian.
	 *
	 * @return string[]
	 */
	public static function get_email_ids() {
		return apply_filters(
			'io_pos_silenced_emails',
			array(
				'new_order',
				'customer_on_hold_order',
				'customer_processing_order',
				'customer_completed_order',
				'customer_invoice',
				'customer_note',
			)
		);
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		foreach ( self::get_email_ids() as $email_id ) {
			add_filter( 'woocommerce_email_recipient_' . $email_id, array( $this, 'filter_recipient' ), 20, 2 );
		}
	}

	/**
	 * Vacía el destinatario de los correos de las ventas del mostrador.
	 *
	 * @param string             $recipient Destinatario.
	 * @param WC_Order|mixed     $order     El pedido.
	 *
	 * @return string
	 */
	public function filter_recipient( $recipient, $order ) {
		if ( IO_POS_Settings::is_enabled( 'notify_emails' ) ) {
			return $recipient;
		}

		if ( ! $order instanceof WC_Order || ! io_pos_is_pos_order( $order ) ) {
			return $recipient;
		}

		return '';
	}
}
