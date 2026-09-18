<?php
/**
 * Emisión de pedidos desde el mostrador.
 *
 * El pedido se emite siempre por el total del trabajo. Lo que se cobró en el
 * momento (todo o una seña) se guarda aparte, como lista de cobros.
 *
 * @package IO\POS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class IO_POS_Order_Builder
 */
class IO_POS_Order_Builder {

	const META_POS      = '_io_pos_order';
	const META_CASHIER  = '_io_pos_cashier';
	const META_CASHIER_NAME = '_io_pos_cashier_name';
	const META_CHANGE   = '_io_pos_change';
	const META_CUSTOM_ITEM = '_io_pos_custom_item';

	/**
	 * Emite un pedido a partir de lo que mandó el mostrador.
	 *
	 * @param array $payload Datos del pedido.
	 *
	 * @return WC_Order|WP_Error
	 */
	public static function create( array $payload ) {
		$items = isset( $payload['items'] ) && is_array( $payload['items'] ) ? $payload['items'] : array();

		if ( ! $items ) {
			return new WP_Error( 'io_pos_empty_cart', __( 'No hay nada cargado en el pedido.', 'io-punto-venta' ) );
		}

		$lines = self::prepare_items( $items );

		if ( is_wp_error( $lines ) ) {
			return $lines;
		}

		$order = wc_create_order( array( 'created_via' => 'io-pos' ) );

		if ( is_wp_error( $order ) ) {
			return $order;
		}

		try {
			$subtotal = self::add_items( $order, $lines );

			self::set_customer( $order, $payload );

			$discount = self::add_discount( $order, $payload, $subtotal );

			if ( is_wp_error( $discount ) ) {
				$order->delete( true );

				return $discount;
			}

			self::add_shipping( $order, $payload );
			self::set_job_data( $order, $payload );
			self::set_urgent_flag( $order );

			$order->update_meta_data( self::META_POS, '1' );
			$order->update_meta_data( self::META_CASHIER, (string) get_current_user_id() );
			$order->update_meta_data( self::META_CASHIER_NAME, io_pos_get_user_name( get_current_user_id() ) );

			if ( ! empty( $payload['customer_note'] ) ) {
				$order->set_customer_note( sanitize_textarea_field( (string) $payload['customer_note'] ) );
			}

			$order->calculate_totals( true );

			$expected = self::check_expected_total( $order, $payload );

			if ( is_wp_error( $expected ) ) {
				$order->delete( true );

				return $expected;
			}

			$payments = self::add_payments( $order, $payload );

			if ( is_wp_error( $payments ) ) {
				$order->delete( true );

				return $payments;
			}

			$change = round( (float) ( $payload['change'] ?? 0 ), wc_get_price_decimals() );

			if ( $change > 0 ) {
				$order->update_meta_data( self::META_CHANGE, wc_format_decimal( $change, wc_get_price_decimals() ) );
			}

			$order->set_payment_method( 'io_pos' );
			$order->set_payment_method_title( self::get_payment_title( $order ) );
			$order->set_status( IO_POS_Payments::get_target_status( $order ) );
			$order->save();

			IO_POS_Job::sanitize_order_meta( $order );

			wc_maybe_reduce_stock_levels( $order->get_id() );

			self::notify_payment( $order );

			/**
			 * Se dispara cuando el mostrador emite un pedido.
			 *
			 * @param WC_Order $order   El pedido.
			 * @param array    $payload Los datos recibidos.
			 */
			do_action( 'io_pos_order_created', $order, $payload );

			return $order;
		} catch ( Exception $exception ) {
			$order->delete( true );

			return new WP_Error( 'io_pos_order_error', $exception->getMessage() );
		}
	}

	/**
	 * Comprueba que el total calculado coincida con el que mostró la pantalla.
	 *
	 * Si la tienda suma impuestos aparte, el total del servidor puede no ser el
	 * que vio la persona al cobrar. Antes que cobrar de menos, se corta.
	 *
	 * @param WC_Order $order   El pedido.
	 * @param array    $payload Datos recibidos.
	 *
	 * @return true|WP_Error
	 */
	protected static function check_expected_total( $order, array $payload ) {
		if ( ! isset( $payload['expected_total'] ) ) {
			return true;
		}

		$expected = round( (float) $payload['expected_total'], wc_get_price_decimals() );
		$total    = round( (float) $order->get_total(), wc_get_price_decimals() );

		if ( abs( $expected - $total ) <= 0.01 ) {
			return true;
		}

		return new WP_Error(
			'io_pos_total_mismatch',
			sprintf(
				/* translators: 1: total de la pantalla, 2: total calculado. */
				__( 'El total cambió al emitir el pedido: la pantalla mostraba %1$s y el sistema calculó %2$s. Revisá los impuestos de la tienda y volvé a cobrar.', 'io-punto-venta' ),
				io_pos_format_price( $expected, $order->get_currency() ),
				io_pos_format_price( $total, $order->get_currency() )
			),
			array( 'expected' => $expected, 'total' => $total )
		);
	}

	/**
	 * Valida las líneas recibidas y las deja listas para cargar.
	 *
	 * @param array $items Líneas crudas.
	 *
	 * @return array|WP_Error
	 */
	protected static function prepare_items( array $items ) {
		$can_edit_price  = current_user_can( 'io_pos_edit_price' );
		$can_custom_item = current_user_can( 'io_pos_custom_item' );
		$allow_custom    = IO_POS_Settings::is_enabled( 'terminal_allow_custom_items' );
		$lines           = array();

		foreach ( $items as $item ) {
			$item = (array) $item;
			$qty  = absint( $item['qty'] ?? 1 );

			if ( $qty < 1 ) {
				continue;
			}

			$note      = sanitize_text_field( $item['note'] ?? '' );
			$is_custom = ! empty( $item['custom'] );

			if ( $is_custom ) {
				if ( ! $allow_custom || ! $can_custom_item ) {
					return new WP_Error( 'io_pos_custom_not_allowed', __( 'No tenés permiso para cargar trabajos a medida.', 'io-punto-venta' ) );
				}

				$name  = sanitize_text_field( $item['name'] ?? '' );
				$price = round( (float) ( $item['price'] ?? 0 ), wc_get_price_decimals() );

				if ( '' === $name ) {
					return new WP_Error( 'io_pos_custom_no_name', __( 'El trabajo a medida necesita una descripción.', 'io-punto-venta' ) );
				}

				if ( $price < 0 ) {
					return new WP_Error( 'io_pos_custom_price', __( 'El precio del trabajo a medida no puede ser negativo.', 'io-punto-venta' ) );
				}

				$lines[] = array(
					'custom' => true,
					'name'   => $name,
					'qty'    => $qty,
					'price'  => $price,
					'note'   => $note,
				);

				continue;
			}

			$product_id = absint( $item['variation_id'] ?? 0 );
			$product_id = $product_id ? $product_id : absint( $item['product_id'] ?? 0 );
			$product    = $product_id ? wc_get_product( $product_id ) : false;

			if ( ! $product ) {
				return new WP_Error(
					'io_pos_product_not_found',
					sprintf(
						/* translators: %d: ID del producto. */
						__( 'El producto %d ya no existe.', 'io-punto-venta' ),
						$product_id
					)
				);
			}

			if ( $product->is_type( 'variable' ) ) {
				return new WP_Error(
					'io_pos_variation_required',
					sprintf(
						/* translators: %s: nombre del producto. */
						__( 'Elegí una variación de «%s».', 'io-punto-venta' ),
						$product->get_name()
					)
				);
			}

			$price = wc_get_price_to_display( $product );

			if ( isset( $item['price'] ) && '' !== $item['price'] ) {
				$custom_price = round( (float) $item['price'], wc_get_price_decimals() );

				if ( abs( $custom_price - (float) $price ) > 0.001 ) {
					if ( ! $can_edit_price ) {
						return new WP_Error( 'io_pos_price_not_allowed', __( 'No tenés permiso para cambiar precios.', 'io-punto-venta' ) );
					}

					if ( $custom_price < 0 ) {
						return new WP_Error( 'io_pos_price_negative', __( 'El precio no puede ser negativo.', 'io-punto-venta' ) );
					}

					$price = $custom_price;
				}
			}

			$lines[] = array(
				'custom'  => false,
				'product' => $product,
				'qty'     => $qty,
				'price'   => round( (float) $price, wc_get_price_decimals() ),
				'note'    => $note,
			);
		}

		if ( ! $lines ) {
			return new WP_Error( 'io_pos_empty_cart', __( 'No hay nada cargado en el pedido.', 'io-punto-venta' ) );
		}

		return $lines;
	}

	/**
	 * Carga las líneas en el pedido.
	 *
	 * @param WC_Order $order El pedido.
	 * @param array    $lines Líneas ya validadas.
	 *
	 * @return float El subtotal de las líneas.
	 */
	protected static function add_items( $order, array $lines ) {
		$subtotal = 0.0;

		foreach ( $lines as $line ) {
			$line_total = round( $line['price'] * $line['qty'], wc_get_price_decimals() );
			$subtotal  += $line_total;

			if ( $line['custom'] ) {
				$item = new WC_Order_Item_Product();

				$item->set_name( $line['name'] );
				$item->set_quantity( $line['qty'] );
				$item->set_subtotal( $line_total );
				$item->set_total( $line_total );
				$item->add_meta_data( self::META_CUSTOM_ITEM, '1', true );

				$order->add_item( $item );
			} else {
				$item_id = $order->add_product(
					$line['product'],
					$line['qty'],
					array(
						'subtotal' => $line_total,
						'total'    => $line_total,
					)
				);

				$item = $item_id ? $order->get_item( $item_id ) : false;
			}

			if ( $line['note'] && $item ) {
				$item->add_meta_data( __( 'Nota', 'io-punto-venta' ), $line['note'], true );
				$item->save();
			}
		}

		return round( $subtotal, wc_get_price_decimals() );
	}

	/**
	 * Asigna el cliente del pedido.
	 *
	 * @param WC_Order $order   El pedido.
	 * @param array    $payload Datos recibidos.
	 */
	protected static function set_customer( $order, array $payload ) {
		$customer_id = absint( $payload['customer_id'] ?? 0 );

		if ( $customer_id ) {
			$fields = io_pos_get_customer_fields( $customer_id );

			if ( $fields ) {
				$order->set_customer_id( $customer_id );

				$vat = $fields['vat'];

				unset( $fields['vat'] );

				$order->set_address( array_filter( $fields, 'strlen' ), 'billing' );

				if ( $vat ) {
					$order->update_meta_data( '_billing_vat', $vat );
				}

				return;
			}
		}

		$guest = isset( $payload['customer'] ) && is_array( $payload['customer'] ) ? $payload['customer'] : array();

		if ( ! $guest ) {
			return;
		}

		$address = array_filter(
			array(
				'first_name' => sanitize_text_field( $guest['first_name'] ?? '' ),
				'last_name'  => sanitize_text_field( $guest['last_name'] ?? '' ),
				'company'    => sanitize_text_field( $guest['company'] ?? '' ),
				'phone'      => sanitize_text_field( $guest['phone'] ?? '' ),
				'email'      => sanitize_email( $guest['email'] ?? '' ),
			),
			'strlen'
		);

		if ( $address ) {
			$order->set_address( $address, 'billing' );
		}

		if ( ! empty( $guest['vat'] ) ) {
			$order->update_meta_data( '_billing_vat', sanitize_text_field( $guest['vat'] ) );
		}
	}

	/**
	 * Agrega el descuento del pedido, si lo hay.
	 *
	 * @param WC_Order $order    El pedido.
	 * @param array    $payload  Datos recibidos.
	 * @param float    $subtotal Subtotal de las líneas.
	 *
	 * @return true|WP_Error
	 */
	protected static function add_discount( $order, array $payload, $subtotal ) {
		$discount = isset( $payload['discount'] ) && is_array( $payload['discount'] ) ? $payload['discount'] : array();
		$amount   = round( (float) ( $discount['amount'] ?? 0 ), wc_get_price_decimals() );

		if ( $amount <= 0 ) {
			return true;
		}

		if ( ! current_user_can( 'io_pos_discount' ) ) {
			return new WP_Error( 'io_pos_discount_not_allowed', __( 'No tenés permiso para aplicar descuentos.', 'io-punto-venta' ) );
		}

		if ( 'percent' === ( $discount['type'] ?? 'fixed' ) ) {
			if ( $amount > 100 ) {
				return new WP_Error( 'io_pos_discount_too_high', __( 'El descuento no puede superar el 100 %.', 'io-punto-venta' ) );
			}

			$value = round( $subtotal * $amount / 100, wc_get_price_decimals() );
			$label = sprintf( '%s %s%%', __( 'Descuento', 'io-punto-venta' ), wc_format_localized_decimal( $amount ) );
		} else {
			$value = $amount;
			$label = __( 'Descuento', 'io-punto-venta' );
		}

		if ( $value > $subtotal ) {
			return new WP_Error( 'io_pos_discount_too_high', __( 'El descuento no puede superar el total del pedido.', 'io-punto-venta' ) );
		}

		$reason = sanitize_text_field( $discount['reason'] ?? '' );

		if ( $reason ) {
			$label .= ' - ' . $reason;
		}

		$fee = new WC_Order_Item_Fee();

		$fee->set_name( $label );
		$fee->set_amount( - $value );
		$fee->set_total( - $value );
		$fee->set_tax_status( 'none' );
		$fee->add_meta_data( '_io_pos_discount', '1', true );

		$order->add_item( $fee );

		return true;
	}

	/**
	 * Agrega el costo de envío, si lo hay.
	 *
	 * @param WC_Order $order   El pedido.
	 * @param array    $payload Datos recibidos.
	 */
	protected static function add_shipping( $order, array $payload ) {
		$shipping = isset( $payload['shipping'] ) && is_array( $payload['shipping'] ) ? $payload['shipping'] : array();
		$amount   = round( (float) ( $shipping['amount'] ?? 0 ), wc_get_price_decimals() );

		if ( $amount <= 0 ) {
			return;
		}

		$item = new WC_Order_Item_Shipping();

		$item->set_method_title( sanitize_text_field( $shipping['label'] ?? __( 'Envío', 'io-punto-venta' ) ) );
		$item->set_method_id( 'io_pos_shipping' );
		$item->set_total( $amount );

		$order->add_item( $item );
	}

	/**
	 * Guarda los datos del trabajo en el pedido.
	 *
	 * @param WC_Order $order   El pedido.
	 * @param array    $payload Datos recibidos.
	 */
	protected static function set_job_data( $order, array $payload ) {
		$job = isset( $payload['job'] ) && is_array( $payload['job'] ) ? $payload['job'] : array();

		foreach ( IO_POS_Job::get_schema() as $key => $field ) {
			if ( ! isset( $job[ $key ] ) ) {
				continue;
			}

			$value = is_scalar( $job[ $key ] ) ? trim( (string) $job[ $key ] ) : '';

			if ( '' !== $value ) {
				$order->update_meta_data( $field['meta_key'], $value );
			}
		}

		if ( ! IO_POS_Settings::is_enabled( 'production_enabled' ) ) {
			return;
		}

		$status = sanitize_key( (string) ( $payload['production_status'] ?? '' ) );

		if ( ! $status ) {
			$status = IO_POS_Job::get_default_production_status();
		}

		IO_POS_Job::set_production_status( $order, $status, 'mostrador' );
	}

	/**
	 * Marca el pedido como urgente si la prioridad elegida lo dice.
	 *
	 * Usa la misma clave que el Panel Taller, para que el aviso se vea allá.
	 *
	 * @param WC_Order $order El pedido.
	 */
	protected static function set_urgent_flag( $order ) {
		$priority = (string) $order->get_meta( IO_POS_Job::META_PRIORITY );

		if ( '' === $priority ) {
			return;
		}

		$urgent = (bool) preg_match( '/urgen|express/i', $priority );

		$order->update_meta_data( IO_POS_Job::META_URGENT, $urgent ? '1' : '0' );
	}

	/**
	 * Registra los cobros del pedido.
	 *
	 * @param WC_Order $order   El pedido.
	 * @param array    $payload Datos recibidos.
	 *
	 * @return true|WP_Error
	 */
	protected static function add_payments( $order, array $payload ) {
		$payments = isset( $payload['payments'] ) && is_array( $payload['payments'] ) ? $payload['payments'] : array();
		$total    = round( (float) $order->get_total(), wc_get_price_decimals() );
		$sum      = 0.0;
		$valid    = array();

		foreach ( $payments as $payment ) {
			$payment = (array) $payment;
			$amount  = round( (float) ( $payment['amount'] ?? 0 ), wc_get_price_decimals() );

			if ( $amount <= 0 ) {
				continue;
			}

			$sum    += $amount;
			$valid[] = array(
				'method' => sanitize_key( $payment['method'] ?? '' ),
				'amount' => $amount,
			);
		}

		$sum = round( $sum, wc_get_price_decimals() );

		if ( $sum > $total + 0.001 ) {
			return new WP_Error( 'io_pos_overpaid', __( 'Lo cobrado no puede superar el total del pedido.', 'io-punto-venta' ) );
		}

		if ( $sum < $total - 0.001 ) {
			if ( ! IO_POS_Settings::is_enabled( 'payment_allow_partial' ) ) {
				return new WP_Error( 'io_pos_partial_disabled', __( 'El cobro parcial está desactivado en los ajustes.', 'io-punto-venta' ) );
			}

			if ( ! current_user_can( 'io_pos_partial_payment' ) ) {
				return new WP_Error( 'io_pos_partial_not_allowed', __( 'No tenés permiso para cobrar una seña.', 'io-punto-venta' ) );
			}
		}

		foreach ( $valid as $payment ) {
			$result = IO_POS_Payments::add_payment(
				$order,
				$payment['method'],
				$payment['amount'],
				array(
					'save'   => false,
					'silent' => true,
				)
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		IO_POS_Payments::recalculate( $order, false );

		return true;
	}

	/**
	 * Manda el aviso de seña al cliente.
	 *
	 * Si se cobró con dos métodos va un solo correo con el total, y si se cobró
	 * todo no va ninguno.
	 *
	 * @param WC_Order $order El pedido.
	 */
	protected static function notify_payment( $order ) {
		$paid = IO_POS_Payments::get_paid_total( $order );

		if ( $paid <= 0 ) {
			return;
		}

		$balance = IO_POS_Payments::get_balance( $order );

		// Solo cuando queda saldo: en una venta cobrada entera el cliente ya se
		// lleva el comprobante impreso, un correo más sería ruido.
		if ( $balance <= 0 ) {
			return;
		}

		$methods = array_keys( IO_POS_Payments::get_totals_by_method( $order ) );

		IO_POS_Payments::maybe_send_email(
			$order,
			array(
				'tipo'   => IO_POS_Payments::TYPE_DEPOSIT,
				'monto'  => $paid,
				'metodo' => (string) reset( $methods ),
			)
		);
	}

	/**
	 * Texto del método de pago que se guarda en el pedido.
	 *
	 * @param WC_Order $order El pedido.
	 *
	 * @return string
	 */
	protected static function get_payment_title( $order ) {
		$labels = array();

		foreach ( IO_POS_Payments::get_totals_by_method( $order ) as $method => $amount ) {
			$labels[] = IO_POS_Payments::get_method_label( $method );
		}

		if ( ! $labels ) {
			return __( 'Mostrador', 'io-punto-venta' );
		}

		return implode( ' + ', array_unique( $labels ) );
	}

	/**
	 * Devuelve el pedido en el formato que usa el mostrador.
	 *
	 * @param WC_Order $order El pedido.
	 * @param bool     $full  Si hay que incluir las líneas y los cobros.
	 *
	 * @return array
	 */
	public static function format_order( $order, $full = true ) {
		$currency = $order->get_currency();
		$balance  = IO_POS_Payments::get_balance( $order );
		$paid     = IO_POS_Payments::get_paid_total( $order );

		$data = array(
			'id'               => $order->get_id(),
			'number'           => $order->get_order_number(),
			'status'           => $order->get_status(),
			'status_label'     => wc_get_order_status_name( $order->get_status() ),
			'date'             => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : '',
			'date_formatted'   => $order->get_date_created() ? wp_date( get_option( 'date_format' ) . ' H:i', $order->get_date_created()->getTimestamp() ) : '',
			'customer'         => self::get_customer_summary( $order ),
			'total'            => (float) $order->get_total(),
			'total_formatted'  => io_pos_format_price( $order->get_total(), $currency ),
			'paid'             => $paid,
			'paid_formatted'   => io_pos_format_price( $paid, $currency ),
			'balance'          => $balance,
			'balance_formatted' => io_pos_format_price( $balance, $currency ),
			'cashier'          => (string) $order->get_meta( self::META_CASHIER_NAME ),
			'delivery_date'    => IO_POS_Job::get_delivery_date( $order ),
			'delivery_formatted' => io_pos_format_date( IO_POS_Job::get_delivery_date( $order ) ),
			'production_status' => (string) $order->get_meta( IO_POS_Job::META_STATUS ),
		);

		$data['production_status_label'] = $data['production_status']
			? IO_POS_Job::get_production_status_label( $data['production_status'] )
			: '';

		if ( ! $full ) {
			return $data;
		}

		$items = array();

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			$qty     = (int) $item->get_quantity();
			$total   = (float) $item->get_total();

			$items[] = array(
				'name'            => $item->get_name(),
				'sku'             => $product ? $product->get_sku() : '',
				'qty'             => $qty,
				'price'           => $qty ? round( $total / $qty, wc_get_price_decimals() ) : $total,
				'price_formatted' => io_pos_format_price( $qty ? $total / $qty : $total, $currency ),
				'total'           => $total,
				'total_formatted' => io_pos_format_price( $total, $currency ),
				'note'            => (string) $item->get_meta( __( 'Nota', 'io-punto-venta' ) ),
			);
		}

		$fees = array();

		foreach ( $order->get_items( 'fee' ) as $fee ) {
			$fees[] = array(
				'name'            => $fee->get_name(),
				'total'           => (float) $fee->get_total(),
				'total_formatted' => io_pos_format_price( $fee->get_total(), $currency ),
			);
		}

		$shipping = array();

		foreach ( $order->get_items( 'shipping' ) as $line ) {
			$shipping[] = array(
				'name'            => $line->get_method_title(),
				'total'           => (float) $line->get_total(),
				'total_formatted' => io_pos_format_price( $line->get_total(), $currency ),
			);
		}

		$payments = array();

		foreach ( IO_POS_Payments::get_payments( $order ) as $payment ) {
			$payments[] = array(
				'method'          => $payment['method'],
				'label'           => IO_POS_Payments::get_method_label( $payment['method'] ),
				'amount'          => (float) $payment['amount'],
				'amount_formatted' => io_pos_format_price( $payment['amount'], $currency ),
				'date'            => $payment['date'] ?? '',
			);
		}

		$job = array();

		foreach ( IO_POS_Job::get_job_details( $order ) as $detail ) {
			$job[] = array(
				'key'     => $detail['key'],
				'label'   => $detail['label'],
				'value'   => $detail['formatted'],
				'receipt' => $detail['receipt'],
			);
		}

		$change = (float) $order->get_meta( self::META_CHANGE );

		$data['items']               = $items;
		$data['fees']                = $fees;
		$data['shipping']            = $shipping;
		$data['payments']            = $payments;
		$data['job']                 = $job;
		$data['note']                = $order->get_customer_note();
		$data['subtotal']            = (float) $order->get_subtotal();
		$data['subtotal_formatted']  = io_pos_format_price( $order->get_subtotal(), $currency );
		$data['tax']                 = (float) $order->get_total_tax();
		$data['tax_formatted']       = io_pos_format_price( $order->get_total_tax(), $currency );
		$data['change']              = $change;
		$data['change_formatted']    = io_pos_format_price( $change, $currency );
		$data['edit_url']            = current_user_can( 'edit_shop_orders' ) ? $order->get_edit_order_url() : '';

		return $data;
	}

	/**
	 * Resumen del cliente de un pedido.
	 *
	 * @param WC_Order $order El pedido.
	 *
	 * @return array
	 */
	protected static function get_customer_summary( $order ) {
		$name = trim( $order->get_formatted_billing_full_name() );

		if ( ! $name ) {
			$name = (string) $order->get_billing_company();
		}

		return array(
			'id'      => $order->get_customer_id(),
			'name'    => $name ? $name : IO_POS_Settings::get( 'terminal_customer_label' ),
			'company' => $order->get_billing_company(),
			'phone'   => $order->get_billing_phone(),
			'email'   => $order->get_billing_email(),
			'vat'     => (string) $order->get_meta( '_billing_vat' ),
		);
	}
}
