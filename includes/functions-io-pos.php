<?php
/**
 * Helper functions.
 *
 * @package IO\POS
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'io_pos' ) ) {
	/**
	 * Main plugin instance.
	 *
	 * @return IO_POS_Plugin
	 */
	function io_pos() {
		return IO_POS_Plugin::instance();
	}
}

if ( ! function_exists( 'io_pos_normalize_date' ) ) {
	/**
	 * Normalize a date to the Y-m-d format.
	 *
	 * Accepts the ISO format used by the POS and the usual local formats, so a
	 * value typed by hand in the admin is stored the same way as one coming
	 * from the register.
	 *
	 * @param mixed $value Raw date.
	 *
	 * @return string The normalized date, or an empty string when invalid.
	 */
	function io_pos_normalize_date( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $value, $matches ) ) {
			return wp_checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1], $value ) ? "{$matches[1]}-{$matches[2]}-{$matches[3]}" : '';
		}

		if ( preg_match( '#^(\d{1,2})[/-](\d{1,2})[/-](\d{2,4})$#', $value, $matches ) ) {
			$day   = (int) $matches[1];
			$month = (int) $matches[2];
			$year  = (int) $matches[3];
			$year  = $year < 100 ? 2000 + $year : $year;

			return wp_checkdate( $month, $day, $year, $value ) ? sprintf( '%04d-%02d-%02d', $year, $month, $day ) : '';
		}

		$timestamp = strtotime( $value );

		return $timestamp ? gmdate( 'Y-m-d', $timestamp ) : '';
	}
}

if ( ! function_exists( 'io_pos_format_date' ) ) {
	/**
	 * Format a Y-m-d date using the date format configured in WordPress.
	 *
	 * @param string $date Date in Y-m-d format.
	 *
	 * @return string
	 */
	function io_pos_format_date( $date ) {
		$date = io_pos_normalize_date( $date );

		if ( ! $date ) {
			return '';
		}

		$timestamp = strtotime( $date . ' 12:00:00' );

		return wp_date( (string) get_option( 'date_format', 'd/m/Y' ), $timestamp );
	}
}

if ( ! function_exists( 'io_pos_today' ) ) {
	/**
	 * Today's date in the site timezone, as Y-m-d.
	 *
	 * @return string
	 */
	function io_pos_today() {
		return current_datetime()->format( 'Y-m-d' );
	}
}

if ( ! function_exists( 'io_pos_days_until' ) ) {
	/**
	 * Number of days between today and a date. Negative when already past.
	 *
	 * @param string $date Date in Y-m-d format.
	 *
	 * @return int|null Null when the date is invalid.
	 */
	function io_pos_days_until( $date ) {
		$date = io_pos_normalize_date( $date );

		if ( ! $date ) {
			return null;
		}

		$today  = new DateTimeImmutable( io_pos_today(), wp_timezone() );
		$target = new DateTimeImmutable( $date, wp_timezone() );

		return (int) $today->diff( $target )->format( '%r%a' );
	}
}

if ( ! function_exists( 'io_pos_delivery_state' ) ) {
	/**
	 * Classify a delivery date so the UI can highlight it.
	 *
	 * @param string $date Date in Y-m-d format.
	 *
	 * @return string One of: overdue, today, soon, scheduled, none.
	 */
	function io_pos_delivery_state( $date ) {
		$days = io_pos_days_until( $date );

		if ( is_null( $days ) ) {
			return 'none';
		}

		if ( $days < 0 ) {
			return 'overdue';
		}

		if ( 0 === $days ) {
			return 'today';
		}

		return $days <= 2 ? 'soon' : 'scheduled';
	}
}

if ( ! function_exists( 'io_pos_get_order' ) ) {
	/**
	 * Get an order object from an ID, a post or an order.
	 *
	 * @param mixed $order Order, order ID or post.
	 *
	 * @return WC_Order|false
	 */
	function io_pos_get_order( $order ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );

		return $order instanceof WC_Order ? $order : false;
	}
}

if ( ! function_exists( 'io_pos_is_pos_order' ) ) {
	/**
	 * Whether an order was created from the point of sale.
	 *
	 * @param mixed $order Order, order ID or post.
	 *
	 * @return bool
	 */
	function io_pos_is_pos_order( $order ) {
		$order = io_pos_get_order( $order );

		if ( ! $order ) {
			return false;
		}

		if ( absint( $order->get_meta( IO_POS_Order_Builder::META_POS ) ) ) {
			return true;
		}

		if ( function_exists( 'yith_pos_is_pos_order' ) ) {
			return (bool) yith_pos_is_pos_order( $order );
		}

		return (bool) absint( $order->get_meta( '_yith_pos_order' ) );
	}
}

if ( ! function_exists( 'io_pos_is_job_order' ) ) {
	/**
	 * Whether an order should be treated as a print job.
	 *
	 * @param mixed $order Order, order ID or post.
	 *
	 * @return bool
	 */
	function io_pos_is_job_order( $order ) {
		$order = io_pos_get_order( $order );

		return $order && IO_POS_Job::has_job_data( $order );
	}
}

if ( ! function_exists( 'io_pos_get_balance_due' ) ) {
	/**
	 * Saldo pendiente de un pedido.
	 *
	 * @param mixed $order Pedido, ID o post.
	 *
	 * @return float
	 */
	function io_pos_get_balance_due( $order ) {
		$order = io_pos_get_order( $order );

		// Un pedido que no lleva el control de cobros del mostrador no tiene
		// saldo pendiente: nadie registró lo que se cobró.
		if ( ! $order || ! io_pos_tracks_payments( $order ) ) {
			return 0.0;
		}

		return IO_POS_Payments::get_balance( $order );
	}
}

if ( ! function_exists( 'io_pos_is_pos_search_request' ) ) {
	/**
	 * Whether the current REST request is a product search coming from the POS.
	 *
	 * @param WP_REST_Request|null $request The request.
	 *
	 * @return bool
	 */
	function io_pos_is_pos_search_request( $request = null ) {
		if ( ! $request instanceof WP_REST_Request ) {
			return false;
		}

		return 'search-products' === ( $request['yith_pos_request'] ?? '' );
	}
}

if ( ! function_exists( 'io_pos_format_price' ) ) {
	/**
	 * Da formato a un importe, en texto plano.
	 *
	 * @param float|string $amount   El importe.
	 * @param string       $currency Moneda; vacío usa la de la tienda.
	 *
	 * @return string
	 */
	function io_pos_format_price( $amount, $currency = '' ) {
		$args = $currency ? array( 'currency' => $currency ) : array();

		return html_entity_decode( wp_strip_all_tags( wc_price( (float) $amount, $args ) ), ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'io_pos_search_product_ids' ) ) {
	/**
	 * Busca productos con el buscador del plugin.
	 *
	 * @param string $search Lo que se escribió.
	 * @param int    $limit  Cantidad máxima de resultados.
	 *
	 * @return int[]
	 */
	function io_pos_search_product_ids( $search, $limit = 0 ) {
		$module = io_pos()->module( 'search' );

		if ( ! $module instanceof IO_POS_Search ) {
			return array();
		}

		return $module->search_ids( $search, $limit );
	}
}

if ( ! function_exists( 'io_pos_get_user_name' ) ) {
	/**
	 * Nombre para mostrar de un usuario.
	 *
	 * @param int $user_id El ID.
	 *
	 * @return string
	 */
	function io_pos_get_user_name( $user_id ) {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return '';
		}

		$name = trim( $user->first_name . ' ' . $user->last_name );

		return $name ? $name : $user->display_name;
	}
}

if ( ! function_exists( 'io_pos_get_terminal_url' ) ) {
	/**
	 * Dirección de la pantalla del mostrador.
	 *
	 * @return string
	 */
	function io_pos_get_terminal_url() {
		$page_id = absint( IO_POS_Settings::get( 'terminal_page_id' ) );

		return $page_id ? (string) get_permalink( $page_id ) : '';
	}
}

if ( ! function_exists( 'io_pos_tracks_payments' ) ) {
	/**
	 * Si el pedido lleva el control de cobros del mostrador.
	 *
	 * Los pedidos anteriores al plugin (o los de otro punto de venta) no tienen
	 * cobros registrados, así que no hay que mostrarles un saldo que no existe.
	 *
	 * @param mixed $order Pedido, ID o post.
	 *
	 * @return bool
	 */
	function io_pos_tracks_payments( $order ) {
		$order = io_pos_get_order( $order );

		if ( ! $order ) {
			return false;
		}

		if ( absint( $order->get_meta( IO_POS_Order_Builder::META_POS ) ) ) {
			return true;
		}

		return (bool) IO_POS_Payments::get_payments( $order );
	}
}

if ( ! function_exists( 'io_pos_get_customer_fields' ) ) {
	/**
	 * Datos de un cliente, con respaldo en los campos del usuario.
	 *
	 * Muchos clientes cargados a mano en WordPress no tienen los campos de
	 * facturación, así que si están vacíos se usa el nombre del usuario.
	 *
	 * @param int $customer_id El ID del usuario.
	 *
	 * @return array<string,string>
	 */
	function io_pos_get_customer_fields( $customer_id ) {
		$user = get_userdata( $customer_id );

		if ( ! $user ) {
			return array();
		}

		$fields = array(
			'first_name' => (string) get_user_meta( $customer_id, 'billing_first_name', true ),
			'last_name'  => (string) get_user_meta( $customer_id, 'billing_last_name', true ),
			'company'    => (string) get_user_meta( $customer_id, 'billing_company', true ),
			'phone'      => (string) get_user_meta( $customer_id, 'billing_phone', true ),
			'email'      => (string) get_user_meta( $customer_id, 'billing_email', true ),
			'address_1'  => (string) get_user_meta( $customer_id, 'billing_address_1', true ),
			'city'       => (string) get_user_meta( $customer_id, 'billing_city', true ),
			'vat'        => (string) get_user_meta( $customer_id, 'billing_vat', true ),
		);

		if ( ! $fields['first_name'] && ! $fields['last_name'] ) {
			$fields['first_name'] = (string) $user->first_name;
			$fields['last_name']  = (string) $user->last_name;
		}

		if ( ! $fields['first_name'] && ! $fields['last_name'] ) {
			$fields['first_name'] = (string) $user->display_name;
		}

		if ( ! $fields['email'] ) {
			$fields['email'] = (string) $user->user_email;
		}

		if ( ! $fields['phone'] ) {
			$fields['phone'] = (string) get_user_meta( $customer_id, 'shipping_phone', true );
		}

		return $fields;
	}
}

if ( ! function_exists( 'io_pos_build_username' ) ) {
	/**
	 * Arma el nombre de usuario de un cliente nuevo: nombre_apellido.
	 *
	 * @param string $first   Nombre.
	 * @param string $last    Apellido.
	 * @param string $company Empresa, por si no hay nombre.
	 *
	 * @return string
	 */
	function io_pos_build_username( $first, $last, $company = '' ) {
		$base = trim( $first . ' ' . $last );
		$base = $base ? $base : $company;
		$base = remove_accents( $base );
		$base = strtolower( trim( preg_replace( '/[\s._-]+/', '_', $base ), '_' ) );
		$base = sanitize_user( $base, true );

		return $base ? $base : 'cliente';
	}
}
