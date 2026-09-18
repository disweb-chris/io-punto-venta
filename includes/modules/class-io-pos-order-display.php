<?php
/**
 * Customer facing output of the job data.
 *
 * @package IO\POS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class IO_POS_Order_Display
 */
class IO_POS_Order_Display {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_order_details' ), 10, 1 );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'render_email_details' ), 10, 4 );
	}

	/**
	 * Get the rows to print for an order.
	 *
	 * @param WC_Order $order The order.
	 *
	 * @return array<string,string> Label => value.
	 */
	protected function get_rows( $order ) {
		$rows = array();

		foreach ( IO_POS_Job::get_job_details( $order ) as $detail ) {
			if ( ! $detail['receipt'] ) {
				continue;
			}

			$rows[ $detail['label'] ] = $detail['formatted'];
		}

		if ( IO_POS_Settings::is_enabled( 'production_enabled' ) ) {
			$status = (string) $order->get_meta( IO_POS_Job::META_STATUS );

			if ( $status ) {
				$rows[ __( 'Estado', 'io-punto-venta' ) ] = IO_POS_Job::get_production_status_label( $status );
			}
		}

		$balance = io_pos_get_balance_due( $order );

		if ( $balance > 0 ) {
			$rows[ __( 'Saldo pendiente', 'io-punto-venta' ) ] = wp_strip_all_tags( wc_price( $balance, array( 'currency' => $order->get_currency() ) ) );
		}

		/**
		 * Filter the job rows shown to the customer.
		 *
		 * @param array    $rows  Label => value.
		 * @param WC_Order $order The order.
		 */
		return apply_filters( 'io_pos_customer_job_rows', $rows, $order );
	}

	/**
	 * Print the job data under the order table.
	 *
	 * @param WC_Order $order The order.
	 */
	public function render_order_details( $order ) {
		if ( ! IO_POS_Settings::is_enabled( 'job_show_on_customer_emails' ) || ! $order instanceof WC_Order ) {
			return;
		}

		$rows = $this->get_rows( $order );

		if ( ! $rows ) {
			return;
		}

		echo '<section class="io-pos-job-details woocommerce-order-details">';
		echo '<h2 class="woocommerce-order-details__title">' . esc_html__( 'Datos del trabajo', 'io-punto-venta' ) . '</h2>';
		echo '<table class="woocommerce-table shop_table io-pos-job-table">';

		foreach ( $rows as $label => $value ) {
			printf(
				'<tr><th scope="row">%1$s</th><td>%2$s</td></tr>',
				esc_html( $label ),
				esc_html( $value )
			);
		}

		echo '</table></section>';
	}

	/**
	 * Print the job data inside the order emails.
	 *
	 * @param WC_Order $order         The order.
	 * @param bool     $sent_to_admin Whether the email goes to the admin.
	 * @param bool     $plain_text    Whether the email is plain text.
	 * @param WC_Email $email         The email object.
	 */
	public function render_email_details( $order, $sent_to_admin = false, $plain_text = false, $email = null ) {
		if ( ! IO_POS_Settings::is_enabled( 'job_show_on_customer_emails' ) || ! $order instanceof WC_Order ) {
			return;
		}

		$rows = $this->get_rows( $order );

		if ( ! $rows ) {
			return;
		}

		if ( $plain_text ) {
			echo "\n" . esc_html( strtoupper( __( 'Datos del trabajo', 'io-punto-venta' ) ) ) . "\n";

			foreach ( $rows as $label => $value ) {
				echo esc_html( $label ) . ': ' . esc_html( $value ) . "\n";
			}

			echo "\n";

			return;
		}

		echo '<h2>' . esc_html__( 'Datos del trabajo', 'io-punto-venta' ) . '</h2>';
		echo '<table cellspacing="0" cellpadding="6" border="1" style="width:100%;margin-bottom:20px;border-collapse:collapse;">';

		foreach ( $rows as $label => $value ) {
			printf(
				'<tr><th style="text-align:left;">%1$s</th><td>%2$s</td></tr>',
				esc_html( $label ),
				esc_html( $value )
			);
		}

		echo '</table>';
	}
}
