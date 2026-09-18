<?php
/**
 * Deposits ("seña") and pending balances.
 *
 * The YITH POS payment screen refuses to close a sale until the whole cart
 * total has been paid, so a deposit is taken by adding a negative fee line to
 * the cart: the customer pays the deposit now and the rest is kept as a
 * pending balance on the order.
 *
 * @package IO\POS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class IO_POS_Deposit
 */
class IO_POS_Deposit {

	const ITEM_META = '_io_pos_balance_line';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'io_pos_order_saved', array( $this, 'sync_order_balance' ), 10, 3 );

		if ( is_admin() ) {
			add_filter( 'woocommerce_order_actions', array( $this, 'add_order_action' ) );
			add_action( 'woocommerce_order_action_io_pos_collect_balance', array( $this, 'collect_balance' ) );
		}
	}

	/**
	 * Whether the deposit workflow is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return IO_POS_Settings::is_enabled( 'deposit_enabled' );
	}

	/**
	 * Find the order item holding the pending balance.
	 *
	 * @param WC_Order $order The order.
	 *
	 * @return WC_Order_Item_Fee|false
	 */
	public static function get_balance_item( $order ) {
		foreach ( $order->get_items( 'fee' ) as $item ) {
			if ( $item->get_meta( self::ITEM_META ) ) {
				return $item;
			}
		}

		return false;
	}

	/**
	 * Recalculate the deposit and balance meta of an order.
	 *
	 * The register sends both values, but they are recomputed here from the
	 * actual order lines so the stored numbers always match the order.
	 *
	 * @param WC_Order             $order    The order.
	 * @param WP_REST_Request|null $request  The request that saved the order.
	 * @param bool                 $creating Whether the order was just created.
	 */
	public function sync_order_balance( $order, $request = null, $creating = true ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$item = self::get_balance_item( $order );

		if ( ! $item ) {
			// Nothing pending: clean up any leftover value sent by the register.
			if ( '' !== (string) $order->get_meta( IO_POS_Job::META_BALANCE ) ) {
				$order->delete_meta_data( IO_POS_Job::META_BALANCE );
				$order->delete_meta_data( IO_POS_Job::META_DEPOSIT );
				$order->delete_meta_data( IO_POS_Job::META_JOB_TOTAL );
				$order->save();
			}

			return;
		}

		$balance   = abs( (float) $item->get_total() );
		$deposit   = (float) $order->get_total();
		$job_total = $deposit + $balance;

		$label = (string) IO_POS_Settings::get( 'deposit_label' );

		if ( $label && $item->get_name() !== $label ) {
			$item->set_name( $label );
			$item->save();
		}

		$order->update_meta_data( IO_POS_Job::META_BALANCE, wc_format_decimal( $balance, wc_get_price_decimals() ) );
		$order->update_meta_data( IO_POS_Job::META_DEPOSIT, wc_format_decimal( $deposit, wc_get_price_decimals() ) );
		$order->update_meta_data( IO_POS_Job::META_JOB_TOTAL, wc_format_decimal( $job_total, wc_get_price_decimals() ) );
		$order->save();

		if ( ! $creating ) {
			return;
		}

		$order->add_order_note(
			sprintf(
				/* translators: 1: deposit amount, 2: pending balance, 3: full job total. */
				__( 'Seña cobrada: %1$s. Saldo pendiente: %2$s. Total del trabajo: %3$s.', 'io-punto-venta' ),
				wp_strip_all_tags( wc_price( $deposit, array( 'currency' => $order->get_currency() ) ) ),
				wp_strip_all_tags( wc_price( $balance, array( 'currency' => $order->get_currency() ) ) ),
				wp_strip_all_tags( wc_price( $job_total, array( 'currency' => $order->get_currency() ) ) )
			)
		);
	}

	/**
	 * Add the "collect balance" action to the order edit screen.
	 *
	 * @param array $actions Available actions.
	 *
	 * @return array
	 */
	public function add_order_action( $actions ) {
		global $theorder;

		if ( $theorder instanceof WC_Order && io_pos_get_balance_due( $theorder ) > 0 ) {
			$actions['io_pos_collect_balance'] = __( 'Registrar el cobro del saldo pendiente', 'io-punto-venta' );
		}

		return $actions;
	}

	/**
	 * Collect the pending balance of an order.
	 *
	 * Removes the negative fee line, recalculates the order so its total is
	 * the full price of the job, and leaves a note with the amount collected.
	 *
	 * @param WC_Order $order The order.
	 */
	public function collect_balance( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$item = self::get_balance_item( $order );

		if ( ! $item ) {
			return;
		}

		$balance = abs( (float) $item->get_total() );

		$order->remove_item( $item->get_id() );
		$order->calculate_totals( false );

		$order->update_meta_data( IO_POS_Job::META_BALANCE, '0' );
		$order->update_meta_data( '_io_pos_balance_paid_date', io_pos_today() );
		$order->save();

		$order->add_order_note(
			sprintf(
				/* translators: 1: collected amount, 2: new order total. */
				__( 'Saldo cobrado: %1$s. Total del pedido actualizado a %2$s.', 'io-punto-venta' ),
				wp_strip_all_tags( wc_price( $balance, array( 'currency' => $order->get_currency() ) ) ),
				wp_strip_all_tags( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) )
			),
			false,
			true
		);

		/**
		 * Fires after the pending balance of an order has been collected.
		 *
		 * @param WC_Order $order   The order.
		 * @param float    $balance The collected amount.
		 */
		do_action( 'io_pos_balance_collected', $order, $balance );
	}
}
