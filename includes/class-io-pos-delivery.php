<?php
/**
 * Fechas de entrega.
 *
 * Calcula cuándo puede estar un trabajo: cada producto puede declarar sus días
 * de producción en su ficha y, si no lo hace, se usa el valor por defecto. Los
 * días se cuentan hábiles, salteando los días que el taller no trabaja y los
 * feriados cargados.
 *
 * @package IO\POS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class IO_POS_Delivery
 */
class IO_POS_Delivery {

	const PRODUCT_META = '_io_pos_production_days';

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( ! IO_POS_Settings::is_enabled( 'delivery_enabled' ) ) {
			return;
		}

		// Ficha de producto.
		add_action( 'woocommerce_product_options_inventory_product_data', array( $this, 'render_product_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_product_field' ) );
		add_action( 'woocommerce_variation_options_inventory', array( $this, 'render_variation_field' ), 10, 3 );
		add_action( 'woocommerce_admin_process_variation_object', array( $this, 'save_variation_field' ), 10, 2 );

		// Compra por la web.
		if ( IO_POS_Settings::is_enabled( 'delivery_checkout_enabled' ) ) {
			add_action( 'woocommerce_after_order_notes', array( $this, 'render_checkout_field' ) );
			add_action( 'woocommerce_checkout_process', array( $this, 'validate_checkout_field' ) );
			add_action( 'woocommerce_checkout_create_order', array( $this, 'save_checkout_field' ), 10, 2 );
		}
	}

	/* --------------------------------------------------------------------- *
	 * Configuración
	 * --------------------------------------------------------------------- */

	/**
	 * Días de la semana en los que se trabaja.
	 *
	 * @return int[] Números de día, 1 lunes a 7 domingo.
	 */
	public static function get_workdays() {
		$raw  = (string) IO_POS_Settings::get( 'delivery_workdays' );
		$days = array();

		foreach ( explode( ',', $raw ) as $day ) {
			$day = (int) trim( $day );

			if ( $day >= 1 && $day <= 7 ) {
				$days[] = $day;
			}
		}

		$days = array_values( array_unique( $days ) );

		return $days ? $days : array( 1, 2, 3, 4, 5 );
	}

	/**
	 * Feriados cargados, en formato Y-m-d.
	 *
	 * @return string[]
	 */
	public static function get_holidays() {
		$holidays = array();

		foreach ( IO_POS_Settings::get_lines( 'delivery_holidays' ) as $line ) {
			$date = io_pos_normalize_date( $line );

			if ( $date ) {
				$holidays[] = $date;
			}
		}

		return array_values( array_unique( $holidays ) );
	}

	/**
	 * Si ese día se trabaja.
	 *
	 * @param DateTimeImmutable $date La fecha.
	 *
	 * @return bool
	 */
	public static function is_working_day( DateTimeImmutable $date ) {
		if ( ! in_array( (int) $date->format( 'N' ), self::get_workdays(), true ) ) {
			return false;
		}

		return ! in_array( $date->format( 'Y-m-d' ), self::get_holidays(), true );
	}

	/* --------------------------------------------------------------------- *
	 * Cálculo
	 * --------------------------------------------------------------------- */

	/**
	 * Suma días hábiles a una fecha.
	 *
	 * El resultado siempre cae en un día laborable: con cero días devuelve el
	 * primer día trabajable desde el que se le pasó.
	 *
	 * @param DateTimeImmutable $start Desde cuándo.
	 * @param int               $days  Días hábiles a sumar.
	 *
	 * @return DateTimeImmutable
	 */
	public static function add_business_days( DateTimeImmutable $start, $days ) {
		$date  = $start;
		$added = 0;
		$days  = max( 0, (int) $days );
		$guard = 0;

		while ( $added < $days && $guard < 3650 ) {
			$date = $date->modify( '+1 day' );
			$guard++;

			if ( self::is_working_day( $date ) ) {
				$added++;
			}
		}

		$guard = 0;

		while ( ! self::is_working_day( $date ) && $guard < 3650 ) {
			$date = $date->modify( '+1 day' );
			$guard++;
		}

		return $date;
	}

	/**
	 * Desde qué día se empieza a contar la producción.
	 *
	 * Pasada la hora de corte, el trabajo entra al taller recién al día
	 * siguiente.
	 *
	 * @return DateTimeImmutable
	 */
	public static function get_start_date() {
		$now   = new DateTimeImmutable( 'now', wp_timezone() );
		$start = $now->setTime( 0, 0, 0 );

		$cutoff = trim( (string) IO_POS_Settings::get( 'delivery_cutoff' ) );

		if ( preg_match( '/^(\d{1,2}):(\d{2})$/', $cutoff, $matches ) ) {
			$limit = $now->setTime( (int) $matches[1], (int) $matches[2], 0 );

			if ( $now > $limit ) {
				$start = $start->modify( '+1 day' );
			}
		}

		return $start;
	}

	/**
	 * Días de producción declarados por un producto.
	 *
	 * @param WC_Product|int $product El producto o su ID.
	 *
	 * @return int|null Null si el producto no declara nada.
	 */
	public static function get_product_days( $product ) {
		$product = $product instanceof WC_Product ? $product : wc_get_product( $product );

		if ( ! $product ) {
			return null;
		}

		$value = $product->get_meta( self::PRODUCT_META, true );

		if ( '' === (string) $value && $product->get_parent_id() ) {
			$parent = wc_get_product( $product->get_parent_id() );

			$value = $parent ? $parent->get_meta( self::PRODUCT_META, true ) : '';
		}

		if ( '' === (string) $value ) {
			return null;
		}

		return max( 0, (int) $value );
	}

	/**
	 * Días de producción por defecto.
	 *
	 * @return int
	 */
	public static function get_default_days() {
		return IO_POS_Settings::get_int( 'delivery_default_days', 0, 365 );
	}

	/**
	 * Días de producción de un conjunto de productos: manda el más lento.
	 *
	 * @param array $products Lista de WC_Product o de IDs.
	 *
	 * @return int
	 */
	public static function get_days_for_products( array $products ) {
		$days = null;

		foreach ( $products as $product ) {
			$product_days = self::get_product_days( $product );
			$product_days = is_null( $product_days ) ? self::get_default_days() : $product_days;

			$days = is_null( $days ) ? $product_days : max( $days, $product_days );
		}

		return is_null( $days ) ? self::get_default_days() : $days;
	}

	/**
	 * Días de producción de lo que hay en el carrito de la web.
	 *
	 * @return int
	 */
	public static function get_days_for_cart() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return self::get_default_days();
		}

		$products = array();

		foreach ( WC()->cart->get_cart() as $item ) {
			if ( ! empty( $item['data'] ) ) {
				$products[] = $item['data'];
			}
		}

		return self::get_days_for_products( $products );
	}

	/**
	 * Primera fecha de entrega posible.
	 *
	 * @param int $days Días de producción.
	 *
	 * @return string Fecha en formato Y-m-d.
	 */
	public static function get_earliest_date( $days = null ) {
		$days = is_null( $days ) ? self::get_default_days() : $days;

		return self::add_business_days( self::get_start_date(), $days )->format( 'Y-m-d' );
	}

	/**
	 * Fechas de entrega para elegir.
	 *
	 * @param int $days  Días de producción.
	 * @param int $count Cuántas ofrecer.
	 *
	 * @return string[] Fechas en formato Y-m-d.
	 */
	public static function get_options( $days = null, $count = null ) {
		$count = is_null( $count ) ? IO_POS_Settings::get_int( 'delivery_max_options', 1, 60 ) : (int) $count;
		$date  = new DateTimeImmutable( self::get_earliest_date( $days ), wp_timezone() );

		$options = array( $date->format( 'Y-m-d' ) );

		while ( count( $options ) < $count ) {
			$date = self::add_business_days( $date, 1 );

			$options[] = $date->format( 'Y-m-d' );
		}

		/**
		 * Filtra las fechas de entrega ofrecidas.
		 *
		 * @param string[] $options Fechas en formato Y-m-d.
		 * @param int      $days    Días de producción usados.
		 */
		return apply_filters( 'io_pos_delivery_options', $options, $days );
	}

	/* --------------------------------------------------------------------- *
	 * Ficha de producto
	 * --------------------------------------------------------------------- */

	/**
	 * Campo de días de producción en el producto.
	 */
	public function render_product_field() {
		woocommerce_wp_text_input(
			array(
				'id'                => self::PRODUCT_META,
				'label'             => __( 'Días de producción', 'io-punto-venta' ),
				'description'       => sprintf(
					/* translators: %d: días por defecto. */
					__( 'Días hábiles que tarda este producto. Vacío usa el valor por defecto (%d).', 'io-punto-venta' ),
					self::get_default_days()
				),
				'desc_tip'          => true,
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
			)
		);
	}

	/**
	 * Guarda el campo del producto.
	 *
	 * @param WC_Product $product El producto.
	 */
	public function save_product_field( $product ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- lo valida WooCommerce.
		$value = isset( $_POST[ self::PRODUCT_META ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ self::PRODUCT_META ] ) ) ) : '';

		if ( '' === $value ) {
			$product->delete_meta_data( self::PRODUCT_META );

			return;
		}

		$product->update_meta_data( self::PRODUCT_META, max( 0, (int) $value ) );
	}

	/**
	 * Campo de días de producción en la variación.
	 *
	 * @param int     $loop           Índice.
	 * @param array   $variation_data Datos.
	 * @param WP_Post $variation      La variación.
	 */
	public function render_variation_field( $loop, $variation_data, $variation ) {
		woocommerce_wp_text_input(
			array(
				'id'                => self::PRODUCT_META . '_' . $loop,
				'name'              => self::PRODUCT_META . '[' . $loop . ']',
				'value'             => get_post_meta( $variation->ID, self::PRODUCT_META, true ),
				'label'             => __( 'Días de producción', 'io-punto-venta' ),
				'wrapper_class'     => 'form-row form-row-full',
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
			)
		);
	}

	/**
	 * Guarda el campo de la variación.
	 *
	 * @param WC_Product_Variation $variation La variación.
	 * @param int                  $loop      Índice.
	 */
	public function save_variation_field( $variation, $loop ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- lo valida WooCommerce.
		$raw   = isset( $_POST[ self::PRODUCT_META ] ) ? (array) wp_unslash( $_POST[ self::PRODUCT_META ] ) : array();
		$value = isset( $raw[ $loop ] ) ? trim( sanitize_text_field( $raw[ $loop ] ) ) : '';

		if ( '' === $value ) {
			$variation->delete_meta_data( self::PRODUCT_META );

			return;
		}

		$variation->update_meta_data( self::PRODUCT_META, max( 0, (int) $value ) );
	}

	/* --------------------------------------------------------------------- *
	 * Compra por la web
	 * --------------------------------------------------------------------- */

	/**
	 * Selector de fecha de entrega en el checkout.
	 */
	public function render_checkout_field() {
		$options  = self::get_options( self::get_days_for_cart() );
		$required = IO_POS_Settings::is_enabled( 'delivery_checkout_required' );
		$slots    = IO_POS_Settings::get_lines( 'job_time_slots' );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$chosen_date = isset( $_POST['io_pos_delivery_date'] ) ? io_pos_normalize_date( wp_unslash( $_POST['io_pos_delivery_date'] ) ) : '';
		$chosen_slot = isset( $_POST['io_pos_delivery_slot'] ) ? sanitize_text_field( wp_unslash( $_POST['io_pos_delivery_slot'] ) ) : '';
		// phpcs:enable

		echo '<div class="io-pos-checkout-delivery">';
		printf( '<h3>%s</h3>', esc_html__( 'Fecha de entrega', 'io-punto-venta' ) );

		printf(
			'<p class="io-pos-checkout-delivery__hint">%s</p>',
			esc_html__( 'Elegí cuándo querés retirar o recibir el trabajo. Las fechas ya contemplan el tiempo de producción.', 'io-punto-venta' )
		);

		echo '<p class="form-row form-row-wide validate-required">';
		printf(
			'<label for="io_pos_delivery_date">%1$s%2$s</label>',
			esc_html__( 'Fecha', 'io-punto-venta' ),
			$required ? ' <abbr class="required" title="' . esc_attr__( 'obligatorio', 'io-punto-venta' ) . '">*</abbr>' : ''
		);

		echo '<select name="io_pos_delivery_date" id="io_pos_delivery_date" class="select">';

		if ( ! $required ) {
			printf( '<option value="">%s</option>', esc_html__( 'Lo antes posible', 'io-punto-venta' ) );
		}

		foreach ( $options as $option ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $option ),
				selected( $option, $chosen_date, false ),
				esc_html( self::format_option( $option ) )
			);
		}

		echo '</select></p>';

		if ( $slots ) {
			echo '<p class="form-row form-row-wide">';
			printf( '<label for="io_pos_delivery_slot">%s</label>', esc_html__( 'Horario', 'io-punto-venta' ) );
			echo '<select name="io_pos_delivery_slot" id="io_pos_delivery_slot" class="select">';
			printf( '<option value="">%s</option>', esc_html__( 'Cualquier horario', 'io-punto-venta' ) );

			foreach ( $slots as $slot ) {
				printf(
					'<option value="%1$s"%2$s>%3$s</option>',
					esc_attr( $slot ),
					selected( $slot, $chosen_slot, false ),
					esc_html( $slot )
				);
			}

			echo '</select></p>';
		}

		echo '</div>';
	}

	/**
	 * Texto de una fecha en el selector.
	 *
	 * @param string $date Fecha en formato Y-m-d.
	 *
	 * @return string
	 */
	public static function format_option( $date ) {
		$timestamp = strtotime( $date . ' 12:00:00' );

		return ucfirst( wp_date( 'l j \d\e F', $timestamp ) );
	}

	/**
	 * Valida el campo del checkout.
	 */
	public function validate_checkout_field() {
		if ( ! IO_POS_Settings::is_enabled( 'delivery_checkout_required' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- lo valida WooCommerce.
		$date = isset( $_POST['io_pos_delivery_date'] ) ? io_pos_normalize_date( wp_unslash( $_POST['io_pos_delivery_date'] ) ) : '';

		if ( ! $date ) {
			wc_add_notice( __( 'Elegí una fecha de entrega para tu pedido.', 'io-punto-venta' ), 'error' );
		}
	}

	/**
	 * Guarda la fecha elegida en el pedido.
	 *
	 * @param WC_Order $order El pedido.
	 * @param array    $data  Datos del checkout.
	 */
	public function save_checkout_field( $order, $data ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- lo valida WooCommerce.
		$date = isset( $_POST['io_pos_delivery_date'] ) ? io_pos_normalize_date( wp_unslash( $_POST['io_pos_delivery_date'] ) ) : '';
		$slot = isset( $_POST['io_pos_delivery_slot'] ) ? sanitize_text_field( wp_unslash( $_POST['io_pos_delivery_slot'] ) ) : '';
		// phpcs:enable

		if ( ! $date ) {
			$date = self::get_earliest_date( self::get_days_for_cart() );
		}

		$order->update_meta_data( IO_POS_Job::META_DELIVERY_DATE, $date );

		if ( $slot ) {
			$order->update_meta_data( IO_POS_Job::META_DELIVERY_TIME, $slot );
		}
	}
}
