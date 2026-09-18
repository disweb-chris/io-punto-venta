<?php
/**
 * Settings handler.
 *
 * @package IO\POS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class IO_POS_Settings
 *
 * Stores every option of the plugin inside a single array option, and exposes
 * typed getters so the rest of the code never has to deal with raw values.
 */
class IO_POS_Settings {

	const OPTION = 'io_pos_settings';

	/**
	 * Runtime cache of the merged settings.
	 *
	 * @var array|null
	 */
	private static $settings = null;

	/**
	 * Default values for every supported setting.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// Buscador.
			'search_enabled'                  => 'yes',
			'search_max_results'              => 30,
			'search_min_chars'                => 2,
			'search_include_sku'              => 'yes',
			'search_include_variations'       => 'yes',
			'search_include_description'      => 'yes',
			'search_force_selectable'         => 'yes',
			'search_show_sku'                 => 'yes',
			'search_show_stock_badge'         => 'yes',

			// Datos del trabajo.
			'job_enabled'                     => 'yes',
			'job_delivery_required'           => 'no',
			'job_default_days'                => 3,
			'job_time_slots'                  => "Mañana (9 a 13)\nTarde (14 a 18)",
			'job_delivery_methods'            => "Retira en el local\nEnvío a domicilio\nEnvío por correo",
			'job_priorities'                  => "Normal\nUrgente\nExpress (24 h)",
			'job_show_on_receipt'             => 'yes',
			'job_show_on_customer_emails'     => 'yes',
			'job_custom_fields'               => "material|Material|text|||\nmedidas|Medidas|text|||ticket\nterminacion|Terminación|select|Sin terminación,Laminado mate,Laminado brillante,Troquelado,Ojalillos||\narchivo=_io_drive_link|Archivo / enlace|text|||",

			// Producción.
			'production_enabled'              => 'yes',
			'production_statuses'             => "diseno|Diseño\nproduccion|Producción\nterminacion|Terminación\ntaller|Taller\nentregado|Entregado",
			'production_default'              => 'diseno',
			'production_done'                 => 'entregado',
			'production_order_status'         => 'processing',
			'production_complete_order_on_done' => 'yes',

			// Mostrador.
			'terminal_enabled'                => 'yes',
			'terminal_page_id'                => 0,
			'terminal_title'                  => 'Mostrador',
			'terminal_products_per_page'      => 24,
			'terminal_show_images'            => 'yes',
			'terminal_allow_custom_items'     => 'yes',
			'terminal_customer_label'         => 'Consumidor final',

			// Cobros.
			'payment_methods'                 => "efectivo|Efectivo\ntransferencia|Transferencia\nmercadopago|Mercado Pago",
			'payment_cash_method'             => 'efectivo',
			'payment_allow_partial'           => 'yes',
			'payment_status_paid'             => 'completed',
			'payment_status_partial'          => 'processing',
			'payment_status_unpaid'           => 'pending',

			// Comprobante.
			'receipt_width'                   => '80mm',
			'receipt_store_name'              => '',
			'receipt_store_details'           => '',
			'receipt_footer'                  => '¡Gracias por su compra!',
			'receipt_show_job'                => 'yes',
			'receipt_auto_print'              => 'yes',
			'notify_emails'                   => 'no',
			'payment_send_email'              => 'yes',
		);
	}

	/**
	 * Get every setting, merged with the defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( is_null( self::$settings ) ) {
			$stored = get_option( self::OPTION, array() );
			$stored = is_array( $stored ) ? $stored : array();

			self::$settings = wp_parse_args( $stored, self::defaults() );
		}

		return self::$settings;
	}

	/**
	 * Get a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Value returned when the setting does not exist.
	 *
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$settings = self::all();

		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	/**
	 * Check whether a yes/no setting is enabled.
	 *
	 * @param string $key Setting key.
	 *
	 * @return bool
	 */
	public static function is_enabled( $key ) {
		return 'yes' === self::get( $key );
	}

	/**
	 * Get an integer setting.
	 *
	 * @param string $key Setting key.
	 * @param int    $min Minimum allowed value.
	 * @param int    $max Maximum allowed value.
	 *
	 * @return int
	 */
	public static function get_int( $key, $min = 0, $max = PHP_INT_MAX ) {
		return max( $min, min( $max, absint( self::get( $key, 0 ) ) ) );
	}

	/**
	 * Get a setting stored as a "one value per line" textarea.
	 *
	 * @param string $key Setting key.
	 *
	 * @return string[]
	 */
	public static function get_lines( $key ) {
		$raw = (string) self::get( $key, '' );
		$raw = str_replace( array( "\r\n", "\r" ), "\n", $raw );

		$lines = array_map( 'trim', explode( "\n", $raw ) );

		return array_values( array_filter( $lines, 'strlen' ) );
	}

	/**
	 * Get a setting stored as "key|Label" lines, as an associative array.
	 *
	 * @param string $key Setting key.
	 *
	 * @return array<string,string>
	 */
	public static function get_pairs( $key ) {
		$pairs = array();

		foreach ( self::get_lines( $key ) as $line ) {
			$parts      = array_map( 'trim', explode( '|', $line, 2 ) );
			$pair_key   = sanitize_key( $parts[0] );
			$pair_label = $parts[1] ?? $parts[0];

			if ( $pair_key ) {
				$pairs[ $pair_key ] = $pair_label;
			}
		}

		return $pairs;
	}

	/**
	 * Persist a set of settings.
	 *
	 * @param array $values Values to store.
	 */
	public static function update( array $values ) {
		$settings = wp_parse_args( $values, self::all() );

		update_option( self::OPTION, $settings );

		self::$settings = null;
	}

	/**
	 * Sanitize the raw values coming from the settings form.
	 *
	 * @param array $input Raw input.
	 *
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input     = is_array( $input ) ? $input : array();
		$defaults  = self::defaults();
		$sanitized = self::all();

		$textareas = array(
			'job_time_slots',
			'job_delivery_methods',
			'job_priorities',
			'job_custom_fields',
			'production_statuses',
			'payment_methods',
			'receipt_store_details',
			'receipt_footer',
		);

		$integers = array(
			'search_max_results'         => array( 1, 100 ),
			'search_min_chars'           => array( 1, 10 ),
			'job_default_days'           => array( 0, 365 ),
			'terminal_page_id'           => array( 0, PHP_INT_MAX ),
			'terminal_products_per_page' => array( 4, 100 ),
		);

		foreach ( $defaults as $key => $default ) {
			// Una casilla ausente significa "desactivada"; cualquier otro campo que
			// no venga en el formulario conserva lo que ya estaba guardado.
			if ( in_array( $key, $textareas, true ) ) {
				if ( ! isset( $input[ $key ] ) ) {
					continue;
				}

				$sanitized[ $key ] = sanitize_textarea_field( wp_unslash( $input[ $key ] ) );
				continue;
			}

			if ( isset( $integers[ $key ] ) ) {
				list( $min, $max ) = $integers[ $key ];

				$current           = $sanitized[ $key ] ?? $default;
				$sanitized[ $key ] = max( $min, min( $max, absint( $input[ $key ] ?? $current ) ) );
				continue;
			}

			if ( 'yes' === $default || 'no' === $default ) {
				$sanitized[ $key ] = isset( $input[ $key ] ) && $input[ $key ] ? 'yes' : 'no';
				continue;
			}

			if ( ! isset( $input[ $key ] ) ) {
				$sanitized[ $key ] = array_key_exists( $key, $sanitized ) ? $sanitized[ $key ] : $default;
				continue;
			}

			$sanitized[ $key ] = sanitize_text_field( wp_unslash( $input[ $key ] ) );
		}

		return $sanitized;
	}
}
