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

	const META_DELIVERY_DATE   = '_io_pos_delivery_date';
	const META_DELIVERY_TIME   = '_io_pos_delivery_time';
	const META_DELIVERY_METHOD = '_io_pos_delivery_method';
	const META_PRIORITY        = '_io_pos_priority';
	const META_STATUS          = '_io_pos_production_status';
	const META_NOTES           = '_io_pos_production_notes';
	const META_FIELD_PREFIX    = '_io_pos_field_';
	const META_DEPOSIT         = '_io_pos_deposit';
	const META_BALANCE         = '_io_pos_balance_due';
	const META_JOB_TOTAL       = '_io_pos_job_total';

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
			$key   = sanitize_key( $parts[0] ?? '' );

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
				'meta_key' => self::META_FIELD_PREFIX . $key,
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

		$schema['notes'] = array(
			'key'      => 'notes',
			'meta_key' => self::META_NOTES,
			'label'    => __( 'Notas de producción', 'io-punto-venta' ),
			'type'     => 'textarea',
			'options'  => array(),
			'required' => false,
			'receipt'  => false,
		);

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
	 * Solo los datos del trabajo: lo cobrado y el saldo son del pedido, no del
	 * trabajo, y los maneja IO_POS_Payments.
	 *
	 * @return string[]
	 */
	public static function get_meta_keys() {
		$keys = wp_list_pluck( self::get_schema(), 'meta_key' );

		$keys[] = self::META_STATUS;

		return array_values( array_unique( $keys ) );
	}

	/**
	 * Get the configured production statuses.
	 *
	 * @return array<string,string>
	 */
	public static function get_production_statuses() {
		$statuses = IO_POS_Settings::get_pairs( 'production_statuses' );

		if ( ! $statuses ) {
			$statuses = array(
				'pendiente' => __( 'Pendiente', 'io-punto-venta' ),
				'entregado' => __( 'Entregado', 'io-punto-venta' ),
			);
		}

		return apply_filters( 'io_pos_production_statuses', $statuses );
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
		return io_pos_normalize_date( $order->get_meta( self::META_DELIVERY_DATE ) );
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
			$value = (string) $order->get_meta( $field['meta_key'] );

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

				if ( '' === $value ) {
					$order->delete_meta_data( $field['meta_key'] );
				} else {
					$order->update_meta_data( $field['meta_key'], $value );
				}
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
