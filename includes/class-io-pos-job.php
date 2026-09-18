<?php
/**
 * Job data model.
 *
 * Every "print job" is a WooCommerce order with some extra meta: the delivery
 * date, the production status and a configurable set of custom fields.
 *
 * @package IO\POS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class IO_POS_Job
 */
class IO_POS_Job {

	/**
	 * Las claves que ya usa el Panel Taller, para no duplicar datos:
	 * la fecha de entrega y la fase son las mismas que ve el taller.
	 */
	const META_DELIVERY_DATE   = '_wn_delivery_date';
	const META_STATUS          = '_wn_fase';
	const META_URGENT          = '_io_urgente';

	const META_DELIVERY_TIME   = '_io_pos_delivery_time';
	const META_DELIVERY_METHOD = '_io_pos_delivery_method';
	const META_PRIORITY        = '_io_pos_priority';
	const META_NOTES           = '_io_pos_production_notes';
	const META_FIELD_PREFIX    = '_io_pos_field_';

	/**
	 * Runtime cache for the parsed field schema.
	 *
	 * @var array|null
	 */
	private static $fields = null;

	/**
	 * Get the custom fields defined in the settings.
	 *
	 * Each line uses the syntax: key|Label|type|option1,option2|flag|flag
	 * where the flags can be "obligatorio" (required) and "ticket" (print it
	 * on the receipt).
	 *
	 * @return array[]
	 */
	public static function get_custom_fields() {
		if ( ! is_null( self::$fields ) ) {
			return self::$fields;
		}

		$fields = array();
		$types  = array( 'text', 'textarea', 'number', 'date', 'select' );

		foreach ( IO_POS_Settings::get_lines( 'job_custom_fields' ) as $line ) {
			$parts = array_map( 'trim', explode( '|', $line ) );
			$raw   = $parts[0] ?? '';

			// "clave=_otra_meta" permite guardar en una clave que ya usa otro
			// módulo, por ejemplo el enlace de Drive del Panel Taller.
			$meta_keys = array();

			if ( false !== strpos( $raw, '=' ) ) {
				list( $raw, $meta_spec ) = array_map( 'trim', explode( '=', $raw, 2 ) );

				// Separadas por coma se guarda en todas, para alimentar de una
				// sola vez a varios módulos.
				$meta_keys = array_values( array_filter( array_map( 'trim', explode( ',', $meta_spec ) ) ) );
			}

			$meta_key = $meta_keys ? array_shift( $meta_keys ) : '';

			$key = sanitize_key( $raw );

			if ( ! $key ) {
				continue;
			}

			$type  = strtolower( $parts[2] ?? 'text' );
			$type  = in_array( $type, $types, true ) ? $type : 'text';
			$flags = strtolower( implode( ' ', array_slice( $parts, 4 ) ) );

			$options = array();
			if ( ! empty( $parts[3] ) ) {
				$options = array_values( array_filter( array_map( 'trim', explode( ',', $parts[3] ) ), 'strlen' ) );
			}

			$fields[ $key ] = array(
				'key'      => $key,
				'meta_key' => $meta_key ? $meta_key : self::META_FIELD_PREFIX . $key,
				'mirror'   => $meta_keys,
				'label'    => $parts[1] ?: $parts[0],
				'type'     => $type,
				'options'  => $options,
				'required' => (bool) preg_match( '/\b(req|obligat\w*|1)\b/', $flags ),
				'receipt'  => (bool) preg_match( '/\b(ticket|recibo|receipt)\b/', $flags ),
			);
		}

		self::$fields = $fields;

		return self::$fields;
	}

	/**
	 * Get the full schema of the job form: built-in fields plus custom ones.
	 *
	 * @return array[]
	 */
	public static function get_schema() {
		$schema = array();

		$schema['delivery_date'] = array(
			'key'      => 'delivery_date',
			'meta_key' => self::META_DELIVERY_DATE,
			'label'    => __( 'Fecha de entrega', 'io-punto-venta' ),
			'type'     => 'date',
			'options'  => array(),
			'required' => IO_POS_Settings::is_enabled( 'job_delivery_required' ),
			'receipt'  => true,
		);

		$time_slots = IO_POS_Settings::get_lines( 'job_time_slots' );
		if ( $time_slots ) {
			$schema['delivery_time'] = array(
				'key'      => 'delivery_time',
				'meta_key' => self::META_DELIVERY_TIME,
				'label'    => __( 'Horario', 'io-punto-venta' ),
				'type'     => 'select',
				'options'  => $time_slots,
				'required' => false,
				'receipt'  => true,
			);
		}

		$methods = IO_POS_Settings::get_lines( 'job_delivery_methods' );
		if ( $methods ) {
			$schema['delivery_method'] = array(
				'key'      => 'delivery_method',
				'meta_key' => self::META_DELIVERY_METHOD,
				'label'    => __( 'Forma de entrega', 'io-punto-venta' ),
				'type'     => 'select',
				'options'  => $methods,
				'required' => false,
				'receipt'  => true,
			);
		}

		$priorities = IO_POS_Settings::get_lines( 'job_priorities' );
		if ( $priorities ) {
			$schema['priority'] = array(
				'key'      => 'priority',
				'meta_key' => self::META_PRIORITY,
				'label'    => __( 'Prioridad', 'io-punto-venta' ),
				'type'     => 'select',
				'options'  => $priorities,
				'required' => false,
				'receipt'  => false,
			);
		}

		foreach ( self::get_custom_fields() as $key => $field ) {
			$schema[ $key ] = $field;
		}

		if ( IO_POS_Settings::is_enabled( 'job_customer_note' ) ) {
			$schema['customer_note'] = array(
				'key'           => 'customer_note',
				// Sin clave meta: es la nota del cliente del propio pedido, la
				// misma que se llena cuando compran por la web.
				'meta_key'      => '',
				'customer_note' => true,
				'label'         => __( 'Observaciones del cliente', 'io-punto-venta' ),
				'type'          => 'textarea',
				'options'       => array(),
				'required'      => false,
				// El comprobante ya imprime la nota del pedido por su cuenta.
				'receipt'       => false,
			);
		}

		$schema['notes'] = array(
			'key'      => 'notes',
			'meta_key' => self::META_NOTES,
			'label'    => __( 'Notas de producción', 'io-punto-venta' ),
			'type'     => 'textarea',
			'options'  => array(),
			'required' => false,
			'receipt'  => false,
		);

		foreach ( $schema as $key => $field ) {
			$schema[ $key ]['mirror']        = isset( $field['mirror'] ) ? (array) $field['mirror'] : array();
			$schema[ $key ]['customer_note'] = ! empty( $field['customer_note'] );
		}

		/**
		 * Filter the job form schema.
		 *
		 * @param array $schema The schema.
		 */
		return apply_filters( 'io_pos_job_schema', $schema );
	}

	/**
	 * Claves meta del formulario del trabajo.
	 *
	 * Solo los datos que se cargan a mano. La fase no cuenta, porque el
	 * mostrador se la pone a todos los pedidos, y lo cobrado es del pedido.
	 *
	 * @return string[]
	 */
	public static function get_meta_keys() {
		$keys = array();

		foreach ( self::get_schema() as $field ) {
			if ( $field['meta_key'] ) {
				$keys[] = $field['meta_key'];
			}
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * Lee el valor de un campo del trabajo.
	 *
	 * @param WC_Order $order El pedido.
	 * @param array    $field El campo del esquema.
	 *
	 * @return string
	 */
	public static function get_field_value( $order, array $field ) {
		if ( ! empty( $field['customer_note'] ) ) {
			return (string) $order->get_customer_note();
		}

		return $field['meta_key'] ? (string) $order->get_meta( $field['meta_key'] ) : '';
	}

	/**
	 * Guarda el valor de un campo del trabajo.
	 *
	 * Un campo puede alimentar varias claves a la vez, para que el dato llegue
	 * a los otros módulos sin tener que cargarlo dos veces.
	 *
	 * @param WC_Order $order El pedido.
	 * @param array    $field El campo del esquema.
	 * @param string   $value El valor.
	 */
	public static function set_field_value( $order, array $field, $value ) {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';

		if ( ! empty( $field['customer_note'] ) ) {
			$order->set_customer_note( sanitize_textarea_field( $value ) );

			return;
		}

		$keys = array_merge( array( $field['meta_key'] ), (array) ( $field['mirror'] ?? array() ) );

		foreach ( array_filter( $keys ) as $meta_key ) {
			if ( '' === $value ) {
				$order->delete_meta_data( $meta_key );
			} else {
				$order->update_meta_data( $meta_key, $value );
			}
		}
	}

	/**
	 * Get the configured production statuses.
	 *
	 * @return array<string,string>
	 */
	public static function get_production_statuses() {
		// Si el Panel Taller (o su snippet de fases) está activo, mandan sus
		// fases: son las mismas que ve el taller en su pantalla.
		if ( function_exists( 'wn_phase_labels' ) ) {
			$statuses = wn_phase_labels();
		} elseif ( function_exists( 'io_taller_phase_labels' ) ) {
			$statuses = io_taller_phase_labels();
		} else {
			$statuses = IO_POS_Settings::get_pairs( 'production_statuses' );
		}

		if ( ! $statuses ) {
			$statuses = array(
				'diseno'    => __( 'Diseño', 'io-punto-venta' ),
				'entregado' => __( 'Entregado', 'io-punto-venta' ),
			);
		}

		return apply_filters( 'io_pos_production_statuses', $statuses );
	}

	/**
	 * Guarda la fase del pedido respetando el sistema del taller.
	 *
	 * @param WC_Order $order  El pedido.
	 * @param string   $status La fase.
	 * @param string   $origen De dónde viene el cambio, para el registro.
	 *
	 * @return bool Si se pudo guardar.
	 */
	public static function set_production_status( $order, $status, $origen = 'mostrador' ) {
		if ( ! array_key_exists( $status, self::get_production_statuses() ) ) {
			return false;
		}

		if ( function_exists( 'wn_phase_transition' ) ) {
			$result = wn_phase_transition( $order, $status, $origen );

			if ( ! is_wp_error( $result ) ) {
				return true;
			}
		}

		$order->update_meta_data( self::META_STATUS, $status );

		return true;
	}

	/**
	 * Get the default production status key.
	 *
	 * @return string
	 */
	public static function get_default_production_status() {
		$statuses = self::get_production_statuses();
		$default  = sanitize_key( IO_POS_Settings::get( 'production_default' ) );

		if ( isset( $statuses[ $default ] ) ) {
			return $default;
		}

		return (string) key( $statuses );
	}

	/**
	 * Get the production status key that marks a job as finished.
	 *
	 * @return string
	 */
	public static function get_done_production_status() {
		$statuses = self::get_production_statuses();
		$done     = sanitize_key( IO_POS_Settings::get( 'production_done' ) );

		return isset( $statuses[ $done ] ) ? $done : '';
	}

	/**
	 * Get the label of a production status.
	 *
	 * @param string $status Status key.
	 *
	 * @return string
	 */
	public static function get_production_status_label( $status ) {
		$statuses = self::get_production_statuses();

		return $statuses[ $status ] ?? $status;
	}

	/**
	 * Get the production status of an order, falling back to the default one.
	 *
	 * @param WC_Order $order The order.
	 *
	 * @return string
	 */
	public static function get_production_status( $order ) {
		$status = (string) $order->get_meta( self::META_STATUS );

		if ( ! $status || ! array_key_exists( $status, self::get_production_statuses() ) ) {
			return self::get_default_production_status();
		}

		return $status;
	}

	/**
	 * Get the delivery date of an order, as stored (Y-m-d) or an empty string.
	 *
	 * @param WC_Order $order The order.
	 *
	 * @return string
	 */
	public static function get_delivery_date( $order ) {
		$date = io_pos_normalize_date( $order->get_meta( self::META_DELIVERY_DATE ) );

		if ( $date ) {
			return $date;
		}

		// Respaldo para los pedidos que cargó el plugin de fechas de YITH.
		return io_pos_normalize_date( $order->get_meta( 'ywcdd_order_delivery_date' ) );
	}

	/**
	 * Whether the order carries job information.
	 *
	 * @param WC_Order $order The order.
	 *
	 * @return bool
	 */
	public static function has_job_data( $order ) {
		if ( self::get_delivery_date( $order ) ) {
			return true;
		}

		foreach ( self::get_meta_keys() as $meta_key ) {
			if ( '' !== (string) $order->get_meta( $meta_key ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get the job data of an order, ready to be printed.
	 *
	 * @param WC_Order $order The order.
	 *
	 * @return array[] List of arrays with key, label, value and formatted value.
	 */
	public static function get_job_details( $order ) {
		$details = array();

		foreach ( self::get_schema() as $key => $field ) {
			$value = self::get_field_value( $order, $field );

			if ( '' === $value ) {
				continue;
			}

			$details[ $key ] = array(
				'key'       => $key,
				'label'     => $field['label'],
				'value'     => $value,
				'formatted' => 'delivery_date' === $key ? io_pos_format_date( $value ) : $value,
				'receipt'   => ! empty( $field['receipt'] ),
			);
		}

		return $details;
	}

	/**
	 * Sanitize and normalize the job meta of an order.
	 *
	 * Called after the POS creates the order through the REST API, so whatever
	 * the browser sent is validated against the configured schema.
	 *
	 * @param WC_Order $order The order.
	 */
	public static function sanitize_order_meta( $order ) {
		$schema  = self::get_schema();
		$changed = false;

		foreach ( $schema as $field ) {
			if ( ! $field['meta_key'] ) {
				continue;
			}

			$raw = $order->get_meta( $field['meta_key'] );

			if ( '' === (string) $raw ) {
				continue;
			}

			switch ( $field['type'] ) {
				case 'date':
					$value = io_pos_normalize_date( $raw );
					break;
				case 'number':
					// Una caja registradora se escribe con coma decimal.
					$number = str_replace( ',', '.', trim( (string) $raw ) );
					$value  = is_numeric( $number ) ? (string) wc_format_decimal( $number ) : '';
					break;
				case 'textarea':
					$value = sanitize_textarea_field( $raw );
					break;
				case 'select':
					$value = in_array( (string) $raw, $field['options'], true ) ? (string) $raw : '';
					break;
				default:
					$value = sanitize_text_field( $raw );
					break;
			}

			if ( (string) $value !== (string) $raw ) {
				$changed = true;

				self::set_field_value( $order, $field, $value );
			}
		}

		$status     = (string) $order->get_meta( self::META_STATUS );
		$has_job    = self::has_job_data( $order );
		$new_status = '';

		if ( $status && array_key_exists( $status, self::get_production_statuses() ) ) {
			$new_status = $status;
		} elseif ( $has_job && IO_POS_Settings::is_enabled( 'production_enabled' ) ) {
			$new_status = self::get_default_production_status();
		}

		if ( $new_status !== $status ) {
			$changed = true;

			if ( $new_status ) {
				$order->update_meta_data( self::META_STATUS, $new_status );
			} else {
				$order->delete_meta_data( self::META_STATUS );
			}
		}

		if ( $changed ) {
			$order->save();
		}
	}
}
