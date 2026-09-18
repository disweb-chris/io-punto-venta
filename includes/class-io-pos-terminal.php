<?php
/**
 * La pantalla del mostrador.
 *
 * @package IO\POS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class IO_POS_Terminal
 */
class IO_POS_Terminal {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'template_include', array( $this, 'load_template' ), 99 );
		add_filter( 'show_admin_bar', array( $this, 'hide_admin_bar' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'template_redirect', array( $this, 'handle_logout' ) );
	}

	/**
	 * Comprueba si estamos en la página del mostrador.
	 *
	 * @return bool
	 */
	public static function is_terminal() {
		$page_id = absint( IO_POS_Settings::get( 'terminal_page_id' ) );

		if ( ! $page_id || ! IO_POS_Settings::is_enabled( 'terminal_enabled' ) ) {
			return false;
		}

		return is_page( $page_id );
	}

	/**
	 * Quién puede vender.
	 *
	 * @return bool
	 */
	public static function can_use() {
		return is_user_logged_in() && current_user_can( 'io_pos_use' );
	}

	/**
	 * Cierra la sesión desde el mostrador.
	 */
	public function handle_logout() {
		if ( ! self::is_terminal() || ! isset( $_GET['io-pos-logout'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'io-pos-logout' ) ) {
			return;
		}

		wp_logout();
		wp_safe_redirect( io_pos_get_terminal_url() );
		exit;
	}

	/**
	 * Oculta la barra de administración en el mostrador.
	 *
	 * @param bool $show Si se muestra.
	 *
	 * @return bool
	 */
	public function hide_admin_bar( $show ) {
		return self::is_terminal() ? false : $show;
	}

	/**
	 * Sirve la plantilla del mostrador.
	 *
	 * @param string $template La plantilla que eligió WordPress.
	 *
	 * @return string
	 */
	public function load_template( $template ) {
		if ( ! self::is_terminal() ) {
			return $template;
		}

		nocache_headers();

		// Con la página cacheada, la clave de seguridad de la API llega vencida
		// y todo responde 403. Estas constantes las respetan los cachés más
		// usados de WordPress.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		if ( ! defined( 'DONOTCACHEOBJECT' ) ) {
			define( 'DONOTCACHEOBJECT', true );
		}

		$custom = locate_template( array( 'io-punto-venta/terminal.php' ) );

		return $custom ? $custom : IO_POS_DIR . 'templates/terminal.php';
	}

	/**
	 * Carga los archivos de la pantalla.
	 */
	public function enqueue_assets() {
		if ( ! self::is_terminal() ) {
			return;
		}

		wp_enqueue_style( 'io-pos-terminal', IO_POS_ASSETS_URL . 'css/terminal.css', array(), IO_POS_VERSION );

		if ( ! self::can_use() ) {
			return;
		}

		wp_enqueue_script( 'io-pos-terminal', IO_POS_ASSETS_URL . 'js/terminal.js', array(), IO_POS_VERSION, true );

		wp_add_inline_script(
			'io-pos-terminal',
			'var ioPosTerminal = ' . wp_json_encode( self::get_bootstrap_data() ) . ';',
			'before'
		);
	}

	/**
	 * Datos con los que arranca la pantalla.
	 *
	 * @return array
	 */
	public static function get_bootstrap_data() {
		$capabilities = array();

		foreach ( array_keys( IO_POS_Install::get_capabilities() ) as $capability ) {
			$capabilities[ $capability ] = current_user_can( $capability );
		}

		$methods = array();

		foreach ( IO_POS_Payments::get_methods() as $key => $label ) {
			$methods[] = array(
				'key'   => $key,
				'label' => $label,
			);
		}

		$fields = array();

		if ( IO_POS_Settings::is_enabled( 'job_enabled' ) ) {
			foreach ( IO_POS_Job::get_schema() as $key => $field ) {
				$fields[] = array(
					'key'      => $key,
					'label'    => $field['label'],
					'type'     => $field['type'],
					'options'  => array_values( $field['options'] ),
					'required' => (bool) $field['required'],
				);
			}
		}

		$statuses = array();

		foreach ( IO_POS_Job::get_production_statuses() as $key => $label ) {
			$statuses[] = array(
				'key'   => $key,
				'label' => $label,
			);
		}

		$categories = array();

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'number'     => 60,
				'orderby'    => 'name',
			)
		);

		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$categories[] = array(
					'id'   => $term->term_id,
					'name' => $term->name,
				);
			}
		}

		$data = array(
			'restUrl'        => esc_url_raw( IO_POS_REST_API::get_base_url() ),
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'logoutUrl'      => wp_nonce_url( add_query_arg( 'io-pos-logout', 1, io_pos_get_terminal_url() ), 'io-pos-logout' ),
			'adminUrl'       => current_user_can( 'edit_shop_orders' ) ? admin_url( 'admin.php?page=io-pos-board' ) : '',
			'title'          => IO_POS_Settings::get( 'terminal_title' ),
			'user'           => array(
				'id'           => get_current_user_id(),
				'name'         => io_pos_get_user_name( get_current_user_id() ),
				'capabilities' => $capabilities,
			),
			'currency'       => array(
				'symbol'    => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
				'decimals'  => wc_get_price_decimals(),
				'decimal'   => wc_get_price_decimal_separator(),
				'thousand'  => wc_get_price_thousand_separator(),
				'position'  => get_option( 'woocommerce_currency_pos', 'left' ),
			),
			'settings'       => array(
				'showImages'       => IO_POS_Settings::is_enabled( 'terminal_show_images' ),
				'allowCustomItems' => IO_POS_Settings::is_enabled( 'terminal_allow_custom_items' ),
				'allowPartial'     => IO_POS_Settings::is_enabled( 'payment_allow_partial' ),
				'cashMethod'       => IO_POS_Payments::get_cash_method(),
				'customerLabel'    => IO_POS_Settings::get( 'terminal_customer_label' ),
				'minChars'         => IO_POS_Settings::get_int( 'search_min_chars', 1, 10 ),
				'receiptWidth'     => IO_POS_Settings::get( 'receipt_width' ),
				'receiptAutoPrint' => IO_POS_Settings::is_enabled( 'receipt_auto_print' ),
				'receiptShowJob'   => IO_POS_Settings::is_enabled( 'receipt_show_job' ),
			),
			'paymentMethods' => $methods,
			'categories'     => $categories,
			'job'            => array(
				'enabled'     => IO_POS_Settings::is_enabled( 'job_enabled' ) && $fields,
				'fields'      => $fields,
				'required'    => IO_POS_Settings::is_enabled( 'job_delivery_required' ),
				'defaultDays' => IO_POS_Settings::get_int( 'job_default_days', 0, 365 ),
			),
			'production'     => array(
				'enabled'  => IO_POS_Settings::is_enabled( 'production_enabled' ),
				'statuses' => $statuses,
				'default'  => IO_POS_Job::get_default_production_status(),
			),
			'store'          => array(
				'name'    => IO_POS_Settings::get( 'receipt_store_name' ) ? IO_POS_Settings::get( 'receipt_store_name' ) : get_bloginfo( 'name' ),
				'details' => IO_POS_Settings::get( 'receipt_store_details' ),
				'footer'  => IO_POS_Settings::get( 'receipt_footer' ),
			),
		);

		/**
		 * Filtra los datos con los que arranca el mostrador.
		 *
		 * @param array $data Los datos.
		 */
		return apply_filters( 'io_pos_terminal_bootstrap', $data );
	}
}
