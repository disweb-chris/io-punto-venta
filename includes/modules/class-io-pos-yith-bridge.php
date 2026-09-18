<?php
/**
 * Puente con YITH Point of Sale.
 *
 * Solo se carga si YITH POS sigue activo, para acompañar la transición al
 * mostrador propio: arregla su buscador y le agrega los datos del trabajo.
 * Se puede borrar el día que YITH se desinstale.
 *
 * @package IO\POS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class IO_POS_Yith_Bridge
 */
class IO_POS_Yith_Bridge {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'yith_pos_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		add_action( 'woocommerce_rest_insert_shop_order_object', array( $this, 'on_order_created' ), 10, 3 );
		add_filter( 'woocommerce_rest_prepare_shop_order_object', array( $this, 'extend_order_response' ), 20, 3 );
		add_filter( 'yith_pos_order_status', array( $this, 'filter_order_status' ), 10, 2 );
	}

	/**
	 * Enqueue the POS assets.
	 *
	 * Hooked before YITH POS enqueues its own bundle, so our filters are
	 * registered by the time the app boots.
	 */
	public function enqueue_scripts() {
		wp_enqueue_style( 'io-pos', IO_POS_ASSETS_URL . 'css/pos.css', array(), IO_POS_VERSION );

		wp_enqueue_script(
			'io-pos',
			IO_POS_ASSETS_URL . 'js/pos.js',
			array( 'wp-hooks', 'wp-i18n' ),
			IO_POS_VERSION,
			true
		);

		wp_add_inline_script(
			'io-pos',
			'var ioPosConfig = ' . wp_json_encode( $this->get_config() ) . ';',
			'before'
		);
	}

	/**
	 * Build the configuration handed over to the POS app.
	 *
	 * @return array
	 */
	protected function get_config() {
		$schema = array();

		foreach ( IO_POS_Job::get_schema() as $key => $field ) {
			$schema[] = array(
				'key'      => $key,
				'metaKey'  => $field['meta_key'],
				'label'    => $field['label'],
				'type'     => $field['type'],
				'options'  => array_values( $field['options'] ),
				'required' => (bool) $field['required'],
				'receipt'  => (bool) $field['receipt'],
			);
		}

		$statuses = array();
		foreach ( IO_POS_Job::get_production_statuses() as $status_key => $label ) {
			$statuses[] = array(
				'key'   => $status_key,
				'label' => $label,
			);
		}

		$config = array(
			'version'    => IO_POS_VERSION,
			'job'        => array(
				'enabled'         => IO_POS_Settings::is_enabled( 'job_enabled' ),
				'fields'          => $schema,
				'required'        => IO_POS_Settings::is_enabled( 'job_delivery_required' ),
				'defaultDays'     => IO_POS_Settings::get_int( 'job_default_days', 0, 365 ),
				'showOnReceipt'   => IO_POS_Settings::is_enabled( 'job_show_on_receipt' ),
				'deliveryMetaKey' => IO_POS_Job::META_DELIVERY_DATE,
			),
			'production' => array(
				'enabled'  => IO_POS_Settings::is_enabled( 'production_enabled' ),
				'statuses' => $statuses,
				'default'  => IO_POS_Job::get_default_production_status(),
				'metaKey'  => IO_POS_Job::META_STATUS,
			),
			// El cobro con seña vive en el mostrador propio, no en YITH.
			'deposit'    => array( 'enabled' => false ),
			'search'     => array(
				'enabled'         => IO_POS_Settings::is_enabled( 'search_enabled' ),
				'minChars'        => IO_POS_Settings::get_int( 'search_min_chars', 1, 10 ),
				'showSku'         => IO_POS_Settings::is_enabled( 'search_show_sku' ),
				'showStockBadge'  => IO_POS_Settings::is_enabled( 'search_show_stock_badge' ),
				'forceSelectable' => IO_POS_Settings::is_enabled( 'search_force_selectable' ),
			),
			'i18n'       => array(
				'jobTitle'        => __( 'Datos del trabajo', 'io-punto-venta' ),
				'menuTitle'       => __( 'Datos del trabajo', 'io-punto-venta' ),
				'save'            => __( 'Guardar', 'io-punto-venta' ),
				'cancel'          => __( 'Cancelar', 'io-punto-venta' ),
				'clear'           => __( 'Vaciar datos', 'io-punto-venta' ),
				'noDelivery'      => __( 'Sin fecha de entrega', 'io-punto-venta' ),
				'deliveryOn'      => __( 'Entrega', 'io-punto-venta' ),
				'requiredError'   => __( 'Completá los campos obligatorios antes de guardar.', 'io-punto-venta' ),
				'requiredToPay'   => __( 'Cargá la fecha de entrega del trabajo antes de cobrar.', 'io-punto-venta' ),
				'deposit'         => __( 'Seña a cobrar ahora', 'io-punto-venta' ),
				'depositHelp'     => __( 'Dejalo vacío para cobrar el total. Si cargás un importe, el resto queda como saldo pendiente.', 'io-punto-venta' ),
				'depositTooHigh'  => __( 'La seña no puede superar el total del trabajo.', 'io-punto-venta' ),
				'depositTooLow'   => __( 'La seña es menor al mínimo configurado.', 'io-punto-venta' ),
				'depositApplied'  => __( 'Seña', 'io-punto-venta' ),
				'balance'         => __( 'Saldo pendiente', 'io-punto-venta' ),
				'jobTotal'        => __( 'Total del trabajo', 'io-punto-venta' ),
				'productionState' => __( 'Estado de producción', 'io-punto-venta' ),
				'today'           => __( 'Hoy', 'io-punto-venta' ),
				'tomorrow'        => __( 'Mañana', 'io-punto-venta' ),
				'inDays'          => __( 'En %d días', 'io-punto-venta' ),
				'overdue'         => __( 'Vencido', 'io-punto-venta' ),
				'unavailable'     => __( 'Esta función no está disponible con esta versión de YITH POS.', 'io-punto-venta' ),
				'selectOption'    => __( 'Elegir…', 'io-punto-venta' ),
			),
		);

		/**
		 * Filter the configuration passed to the POS app.
		 *
		 * @param array $config The configuration.
		 */
		return apply_filters( 'io_pos_register_config', $config );
	}

	/**
	 * Normalize the job data of an order created from the register.
	 *
	 * @param WC_Order        $order    The order.
	 * @param WP_REST_Request $request  The request.
	 * @param bool            $creating Whether the order is being created.
	 */
	public function on_order_created( $order, $request, $creating ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		IO_POS_Job::sanitize_order_meta( $order );

		/**
		 * Fires once the job data of a POS order has been normalized.
		 *
		 * @param WC_Order        $order    The order.
		 * @param WP_REST_Request $request  The request.
		 * @param bool            $creating Whether the order is being created.
		 */
		do_action( 'io_pos_order_saved', $order, $request, $creating );
	}

	/**
	 * Add the job data to the REST response of an order.
	 *
	 * The receipt printed by the POS reads the order straight from this
	 * response, so everything it needs has to travel with it.
	 *
	 * @param WP_REST_Response $response The response.
	 * @param WC_Order         $order    The order.
	 * @param WP_REST_Request  $request  The request.
	 *
	 * @return WP_REST_Response
	 */
	public function extend_order_response( $response, $order, $request ) {
		if ( ! is_object( $response ) || ! isset( $response->data ) || ! $order instanceof WC_Order ) {
			return $response;
		}

		$details = array();

		foreach ( IO_POS_Job::get_job_details( $order ) as $detail ) {
			$details[] = array(
				'key'       => $detail['key'],
				'label'     => $detail['label'],
				'value'     => $detail['value'],
				'formatted' => $detail['formatted'],
				'receipt'   => $detail['receipt'],
			);
		}

		$production_status = (string) $order->get_meta( IO_POS_Job::META_STATUS );

		$response->data['io_pos_job'] = array(
			'has_job'                 => ! empty( $details ),
			'details'                 => $details,
			'delivery_date'           => IO_POS_Job::get_delivery_date( $order ),
			'delivery_date_formatted' => io_pos_format_date( IO_POS_Job::get_delivery_date( $order ) ),
			'production_status'       => $production_status,
			'production_status_label' => $production_status ? IO_POS_Job::get_production_status_label( $production_status ) : '',
			'deposit'                 => IO_POS_Payments::get_paid_total( $order ),
			'balance_due'             => io_pos_get_balance_due( $order ),
			'job_total'               => (float) $order->get_total(),
		);

		return $response;
	}

	/**
	 * Use a different order status for orders that carry a print job.
	 *
	 * A job that still has to be produced (or that has a pending balance) is
	 * not a finished sale, so leaving it as "completed" hides the work that is
	 * still on the bench.
	 *
	 * @param string   $status The status YITH POS calculated.
	 * @param WC_Order $order  The order.
	 *
	 * @return string
	 */
	public function filter_order_status( $status, $order ) {
		if ( ! IO_POS_Settings::is_enabled( 'production_enabled' ) || ! $order instanceof WC_Order ) {
			return $status;
		}

		if ( ! IO_POS_Job::has_job_data( $order ) ) {
			return $status;
		}

		$job_status = IO_POS_Settings::get( 'production_order_status' );
		$job_status = is_string( $job_status ) ? str_replace( 'wc-', '', $job_status ) : '';

		if ( ! $job_status || ! array_key_exists( 'wc-' . $job_status, wc_get_order_statuses() ) ) {
			return $status;
		}

		return $job_status;
	}
}
