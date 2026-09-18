<?php
/**
 * Cobros de un pedido.
 *
 * Los cobros se guardan en `_io_pagos_historial`, la misma clave y el mismo
 * formato que usan el metabox "Registro de Pagos" y el módulo de finanzas, así
 * lo que se cobra en el mostrador aparece en los dos lados sin duplicar nada.
 *
 * Cada cobro es un array con: tipo (seña, saldo o pago), metodo, monto, fecha y
 * user.
 *
 * @package IO\POS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class IO_POS_Payments
 */
class IO_POS_Payments {

	const META_HISTORY = '_io_pagos_historial';

	// Derivadas: solo para poder filtrar y ordenar por saldo sin recorrer el
	// historial en cada fila del listado.
	const META_PAID    = '_io_pos_paid_total';
	const META_BALANCE = '_io_pos_balance_due';

	const TYPE_DEPOSIT = 'seña';
	const TYPE_BALANCE = 'saldo';
	const TYPE_FULL    = 'pago';

	/**
	 * Métodos de cobro configurados.
	 *
	 * @return array<string,string> Clave => etiqueta.
	 */
	public static function get_methods() {
		$methods = IO_POS_Settings::get_pairs( 'payment_methods' );

		if ( ! $methods ) {
			$methods = array(
				'efectivo'      => __( 'Efectivo', 'io-punto-venta' ),
				'transferencia' => __( 'Transferencia', 'io-punto-venta' ),
				'mercadopago'   => __( 'Mercado Pago', 'io-punto-venta' ),
			);
		}

		/**
		 * Filtra los métodos de cobro del mostrador.
		 *
		 * @param array $methods Clave => etiqueta.
		 */
		return apply_filters( 'io_pos_payment_methods', $methods );
	}

	/**
	 * Etiqueta de un método de cobro.
	 *
	 * @param string $method Clave del método.
	 *
	 * @return string
	 */
	public static function get_method_label( $method ) {
		$methods = self::get_methods();

		return $methods[ $method ] ?? $method;
	}

	/**
	 * Clave del método que se considera efectivo.
	 *
	 * @return string
	 */
	public static function get_cash_method() {
		$methods = self::get_methods();
		$cash    = sanitize_key( (string) IO_POS_Settings::get( 'payment_cash_method' ) );

		return isset( $methods[ $cash ] ) ? $cash : (string) key( $methods );
	}

	/**
	 * Historial tal cual está guardado.
	 *
	 * @param WC_Order $order El pedido.
	 *
	 * @return array[]
	 */
	public static function get_history( $order ) {
		$history = $order->get_meta( self::META_HISTORY );

		// El metabox de pagos guarda con update_post_meta; si el pedido todavía
		// no tiene el dato en memoria, lo buscamos ahí.
		if ( ! is_array( $history ) || ! $history ) {
			$stored = get_post_meta( $order->get_id(), self::META_HISTORY, true );

			if ( is_array( $stored ) ) {
				$history = $stored;
			}
		}

		return is_array( $history ) ? array_values( array_filter( $history, 'is_array' ) ) : array();
	}

	/**
	 * Cobros de un pedido, normalizados.
	 *
	 * @param WC_Order $order El pedido.
	 *
	 * @return array[]
	 */
	public static function get_payments( $order ) {
		$payments = array();

		foreach ( self::get_history( $order ) as $entry ) {
			if ( ! isset( $entry['monto'] ) ) {
				continue;
			}

			$method = (string) ( $entry['metodo'] ?? '' );

			$payments[] = array(
				'type'   => (string) ( $entry['tipo'] ?? self::TYPE_FULL ),
				'method' => $method,
				'label'  => self::get_method_label( $method ),
				'amount' => (float) $entry['monto'],
				'date'   => (string) ( $entry['fecha'] ?? '' ),
				'user'   => (string) ( $entry['user'] ?? '' ),
			);
		}

		return $payments;
	}

	/**
	 * Total cobrado de un pedido.
	 *
	 * @param WC_Order $order El pedido.
	 *
	 * @return float
	 */
	public static function get_paid_total( $order ) {
		$total = 0.0;

		foreach ( self::get_payments( $order ) as $payment ) {
			$total += (float) $payment['amount'];
		}

		return round( $total, wc_get_price_decimals() );
	}

	/**
	 * Saldo pendiente de un pedido.
	 *
	 * @param WC_Order $order El pedido.
	 *
	 * @return float
	 */
	public static function get_balance( $order ) {
		$balance = (float) $order->get_total() - self::get_paid_total( $order );

		return round( max( 0, $balance ), wc_get_price_decimals() );
	}

	/**
	 * Total cobrado por método.
	 *
	 * @param WC_Order $order El pedido.
	 *
	 * @return array<string,float>
	 */
	public static function get_totals_by_method( $order ) {
		$totals = array();

		foreach ( self::get_payments( $order ) as $payment ) {
			$method = (string) $payment['method'];

			$totals[ $method ] = round( ( $totals[ $method ] ?? 0 ) + (float) $payment['amount'], wc_get_price_decimals() );
		}

		return $totals;
	}

	/**
	 * Qué tipo de cobro es este, con los nombres que usa el metabox de pagos.
	 *
	 * @param WC_Order $order  El pedido.
	 * @param float    $amount Importe que se está cobrando.
	 *
	 * @return string
	 */
	public static function guess_type( $order, $amount ) {
		$already = self::get_paid_total( $order );
		$total   = round( (float) $order->get_total(), wc_get_price_decimals() );
		$after   = round( $already + $amount, wc_get_price_decimals() );

		if ( $already > 0 ) {
			return self::TYPE_BALANCE;
		}

		return $after < $total - 0.001 ? self::TYPE_DEPOSIT : self::TYPE_FULL;
	}

	/**
	 * Registra un cobro en un pedido.
	 *
	 * @param WC_Order $order  El pedido.
	 * @param string   $method Clave del método de cobro.
	 * @param float    $amount Importe cobrado.
	 * @param array    $args   Datos extra: type, note, user_id, date, save, silent.
	 *
	 * @return array|WP_Error El cobro registrado.
	 */
	public static function add_payment( $order, $method, $amount, $args = array() ) {
		$amount = round( (float) $amount, wc_get_price_decimals() );

		if ( $amount <= 0 ) {
			return new WP_Error( 'io_pos_invalid_amount', __( 'El importe del cobro tiene que ser mayor que cero.', 'io-punto-venta' ) );
		}

		$methods = self::get_methods();
		$method  = sanitize_key( $method );

		if ( ! isset( $methods[ $method ] ) ) {
			return new WP_Error( 'io_pos_invalid_method', __( 'El método de cobro no existe.', 'io-punto-venta' ) );
		}

		$args = wp_parse_args(
			$args,
			array(
				'type'    => '',
				'user_id' => get_current_user_id(),
				'date'    => '',
				'save'    => true,
				'silent'  => false,
			)
		);

		$type = $args['type'] ? $args['type'] : self::guess_type( $order, $amount );
		$user = $args['user_id'] ? io_pos_get_user_name( $args['user_id'] ) : '';

		$entry = array(
			'tipo'   => $type,
			'metodo' => $method,
			'monto'  => $amount,
			'fecha'  => $args['date'] ? $args['date'] : wp_date( 'd/m/Y H:i' ),
			'user'   => $user,
		);

		$history   = self::get_history( $order );
		$history[] = $entry;

		$order->update_meta_data( self::META_HISTORY, $history );

		self::recalculate( $order, false );

		if ( ! $args['silent'] ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: tipo de cobro, 2: importe, 3: método, 4: saldo pendiente. */
					__( '%1$s — %2$s vía %3$s | Mostrador. Saldo pendiente: %4$s.', 'io-punto-venta' ),
					ucfirst( $type ),
					wp_strip_all_tags( wc_price( $amount, array( 'currency' => $order->get_currency() ) ) ),
					$methods[ $method ],
					wp_strip_all_tags( wc_price( self::get_balance( $order ), array( 'currency' => $order->get_currency() ) ) )
				)
			);
		}

		if ( $args['save'] ) {
			$order->save();
		}

		/**
		 * Se dispara cuando se registra un cobro.
		 *
		 * @param WC_Order $order   El pedido.
		 * @param array    $payment El cobro, con las claves del historial.
		 */
		do_action( 'io_pos_payment_recorded', $order, $entry );

		return $entry;
	}

	/**
	 * Recalcula lo cobrado y el saldo, y ajusta la fecha de pago.
	 *
	 * @param WC_Order $order El pedido.
	 * @param bool     $save  Si hay que guardar el pedido.
	 */
	public static function recalculate( $order, $save = true ) {
		$paid    = self::get_paid_total( $order );
		$balance = self::get_balance( $order );

		$order->update_meta_data( self::META_PAID, wc_format_decimal( $paid, wc_get_price_decimals() ) );
		$order->update_meta_data( self::META_BALANCE, wc_format_decimal( $balance, wc_get_price_decimals() ) );

		if ( $paid > 0 && $balance <= 0 && ! $order->get_date_paid( 'edit' ) ) {
			$order->set_date_paid( time() );
		}

		if ( $save ) {
			$order->save();
		}
	}

	/**
	 * Estado de WooCommerce que le corresponde a un pedido según su cobro.
	 *
	 * @param WC_Order $order El pedido.
	 *
	 * @return string Estado sin el prefijo "wc-".
	 */
	public static function get_target_status( $order ) {
		$balance = self::get_balance( $order );
		$paid    = self::get_paid_total( $order );

		if ( $balance > 0 ) {
			$status = $paid > 0
				? IO_POS_Settings::get( 'payment_status_partial' )
				: IO_POS_Settings::get( 'payment_status_unpaid' );
		} elseif ( IO_POS_Settings::is_enabled( 'production_enabled' ) && IO_POS_Job::has_job_data( $order ) ) {
			$status = IO_POS_Settings::get( 'production_order_status' );
		} else {
			$status = IO_POS_Settings::get( 'payment_status_paid' );
		}

		$status = str_replace( 'wc-', '', (string) $status );

		if ( ! array_key_exists( 'wc-' . $status, wc_get_order_statuses() ) ) {
			$status = 'processing';
		}

		/**
		 * Filtra el estado que se le asigna a un pedido del mostrador.
		 *
		 * @param string   $status El estado, sin el prefijo "wc-".
		 * @param WC_Order $order  El pedido.
		 */
		return apply_filters( 'io_pos_order_target_status', $status, $order );
	}

	/**
	 * Avisa al cliente por correo, usando el email del metabox de pagos.
	 *
	 * @param WC_Order $order   El pedido.
	 * @param array    $payment El cobro registrado.
	 */
	public static function maybe_send_email( $order, array $payment ) {
		if ( ! IO_POS_Settings::is_enabled( 'payment_send_email' ) ) {
			return;
		}

		if ( ! function_exists( 'io_enviar_email_pago' ) || ! $order->get_billing_email() ) {
			return;
		}

		$labels = array(
			self::TYPE_DEPOSIT => '🟡 Seña',
			self::TYPE_BALANCE => '🟢 Saldo',
			self::TYPE_FULL    => '✅ Pago completo',
		);

		io_enviar_email_pago(
			$order,
			$payment['tipo'],
			(float) $payment['monto'],
			$labels[ $payment['tipo'] ] ?? $payment['tipo'],
			self::get_method_label( $payment['metodo'] ),
			self::get_balance( $order )
		);
	}
}
