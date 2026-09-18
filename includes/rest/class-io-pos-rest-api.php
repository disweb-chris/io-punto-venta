<?php
/**
 * API del mostrador.
 *
 * Todo lo que hace la pantalla del mostrador pasa por acá: buscar productos,
 * buscar y crear clientes, emitir el pedido, registrar cobros y consultar el
 * historial. No usa la API de WooCommerce a propósito: así los permisos son los
 * del mostrador y no los de la tienda.
 *
 * @package IO\POS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class IO_POS_REST_API
 */
class IO_POS_REST_API {

	const NAMESPACE_V1 = 'io-pos/v1';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Dirección base de la API, siempre con la barra final.
	 *
	 * Sin esa barra, el mostrador pide /io-pos/v1products y WordPress responde
	 * 404, porque no existe ninguna ruta con ese nombre.
	 *
	 * @return string
	 */
	public static function get_base_url() {
		return trailingslashit( rest_url( self::NAMESPACE_V1 ) );
	}

	/**
	 * Registra las rutas.
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/products',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_products' ),
				'permission_callback' => array( $this, 'can_use' ),
				'args'                => array(
					'search'   => array( 'type' => 'string' ),
					'category' => array( 'type' => 'integer' ),
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/products/(?P<id>\d+)/variations',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_variations' ),
				'permission_callback' => array( $this, 'can_use' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/customers',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_customers' ),
					'permission_callback' => array( $this, 'can_use' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_customer' ),
					'permission_callback' => array( $this, 'can_manage_customers' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/orders',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_order' ),
					'permission_callback' => array( $this, 'can_use' ),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_orders' ),
					'permission_callback' => array( $this, 'can_view_history' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/orders/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_order' ),
				'permission_callback' => array( $this, 'can_view_history' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/orders/(?P<id>\d+)/payments',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_payment' ),
				'permission_callback' => array( $this, 'can_collect_balance' ),
			)
		);
	}

	/* --------------------------------------------------------------------- *
	 * Permisos
	 * --------------------------------------------------------------------- */

	/**
	 * Puede usar el mostrador.
	 *
	 * @return bool|WP_Error
	 */
	public function can_use() {
		return current_user_can( 'io_pos_use' ) ? true : $this->forbidden();
	}

	/**
	 * Puede ver el historial.
	 *
	 * @return bool|WP_Error
	 */
	public function can_view_history() {
		return current_user_can( 'io_pos_view_history' ) ? true : $this->forbidden();
	}

	/**
	 * Puede crear clientes.
	 *
	 * @return bool|WP_Error
	 */
	public function can_manage_customers() {
		return current_user_can( 'io_pos_manage_customers' ) ? true : $this->forbidden();
	}

	/**
	 * Puede cobrar saldos.
	 *
	 * @return bool|WP_Error
	 */
	public function can_collect_balance() {
		return current_user_can( 'io_pos_collect_balance' ) ? true : $this->forbidden();
	}

	/**
	 * Error de permisos.
	 *
	 * @return WP_Error
	 */
	protected function forbidden() {
		return new WP_Error(
			'io_pos_forbidden',
			__( 'No tenés permiso para hacer esto.', 'io-punto-venta' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/* --------------------------------------------------------------------- *
	 * Productos
	 * --------------------------------------------------------------------- */

	/**
	 * Lista o busca productos.
	 *
	 * @param WP_REST_Request $request La petición.
	 *
	 * @return WP_REST_Response
	 */
	public function get_products( $request ) {
		$search   = trim( (string) $request->get_param( 'search' ) );
		$category = absint( $request->get_param( 'category' ) );
		$page     = max( 1, absint( $request->get_param( 'page' ) ) );
		$per_page = IO_POS_Settings::get_int( 'terminal_products_per_page', 4, 100 );

		$products = array();
		$has_more = false;

		if ( '' !== $search ) {
			$ids = io_pos_search_product_ids( $search, IO_POS_Settings::get_int( 'search_max_results', 1, 100 ) );

			foreach ( $ids as $id ) {
				$product = wc_get_product( $id );

				if ( $product ) {
					$products[] = self::format_product( $product );
				}
			}
		} else {
			$args = array(
				'status'   => 'publish',
				'limit'    => $per_page,
				'page'     => $page,
				'orderby'  => 'title',
				'order'    => 'ASC',
				'paginate' => true,
			);

			if ( $category ) {
				$term = get_term( $category, 'product_cat' );

				if ( $term && ! is_wp_error( $term ) ) {
					$args['category'] = array( $term->slug );
				}
			}

			$results = wc_get_products( apply_filters( 'io_pos_terminal_products_query', $args, $request ) );

			foreach ( $results->products as $product ) {
				$products[] = self::format_product( $product );
			}

			$has_more = $page < (int) $results->max_num_pages;
		}

		return rest_ensure_response(
			array(
				'products' => $products,
				'has_more' => $has_more,
				'page'     => $page,
			)
		);
	}

	/**
	 * Variaciones de un producto.
	 *
	 * @param WP_REST_Request $request La petición.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_variations( $request ) {
		$product = wc_get_product( absint( $request['id'] ) );

		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return new WP_Error( 'io_pos_not_found', __( 'El producto no tiene variaciones.', 'io-punto-venta' ), array( 'status' => 404 ) );
		}

		$variations = array();

		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );

			if ( ! $variation ) {
				continue;
			}

			$data                = self::format_product( $variation );
			$data['name']        = $product->get_name();
			$data['description'] = wc_get_formatted_variation( $variation, true, false, false );

			$variations[] = $data;
		}

		return rest_ensure_response( array( 'variations' => $variations ) );
	}

	/**
	 * Convierte un producto al formato que usa el mostrador.
	 *
	 * @param WC_Product $product El producto.
	 *
	 * @return array
	 */
	public static function format_product( $product ) {
		$image_id = $product->get_image_id();

		if ( ! $image_id && $product->get_parent_id() ) {
			$parent = wc_get_product( $product->get_parent_id() );

			if ( $parent ) {
				$image_id = $parent->get_image_id();
			}
		}

		$image = $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : '';

		return array(
			'id'             => $product->get_id(),
			'parent_id'      => $product->get_parent_id(),
			'name'           => $product->get_name(),
			'sku'            => $product->get_sku(),
			'type'           => $product->get_type(),
			'price'          => (float) wc_get_price_to_display( $product ),
			'has_price'      => '' !== $product->get_price(),
			'image'          => $image ? $image : '',
			'manage_stock'   => (bool) $product->managing_stock(),
			'stock_quantity' => $product->get_stock_quantity(),
			'stock_status'   => $product->get_stock_status(),
			'is_variable'    => $product->is_type( 'variable' ),
			'production_days' => IO_POS_Delivery::get_product_days( $product ),
		);
	}

	/* --------------------------------------------------------------------- *
	 * Clientes
	 * --------------------------------------------------------------------- */

	/**
	 * Busca clientes.
	 *
	 * @param WP_REST_Request $request La petición.
	 *
	 * @return WP_REST_Response
	 */
	public function get_customers( $request ) {
		$search = trim( (string) $request->get_param( 'search' ) );

		if ( strlen( $search ) < 2 ) {
			return rest_ensure_response( array( 'customers' => array() ) );
		}

		$query = new WP_User_Query(
			array(
				'search'         => '*' . esc_attr( $search ) . '*',
				'search_columns' => array( 'user_login', 'user_email', 'display_name', 'user_nicename' ),
				'number'         => 20,
				'fields'         => 'ID',
			)
		);

		$ids = $query->get_results();

		// Buscar también por teléfono y por apellido, que es como se busca en el mostrador.
		$by_meta = get_users(
			array(
				'number'     => 20,
				'fields'     => 'ID',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'OR',
					array(
						'key'     => 'billing_phone',
						'value'   => $search,
						'compare' => 'LIKE',
					),
					array(
						'key'     => 'billing_last_name',
						'value'   => $search,
						'compare' => 'LIKE',
					),
					array(
						'key'     => 'billing_company',
						'value'   => $search,
						'compare' => 'LIKE',
					),
				),
			)
		);

		$ids       = array_slice( array_unique( array_merge( (array) $ids, (array) $by_meta ) ), 0, 25 );
		$customers = array();

		foreach ( $ids as $id ) {
			$customers[] = self::format_customer( $id );
		}

		return rest_ensure_response( array( 'customers' => $customers ) );
	}

	/**
	 * Crea un cliente.
	 *
	 * @param WP_REST_Request $request La petición.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_customer( $request ) {
		$data = (array) $request->get_param( 'customer' );

		$first_name = sanitize_text_field( $data['first_name'] ?? '' );
		$last_name  = sanitize_text_field( $data['last_name'] ?? '' );
		$company    = sanitize_text_field( $data['company'] ?? '' );
		$phone      = sanitize_text_field( $data['phone'] ?? '' );
		$email      = sanitize_email( $data['email'] ?? '' );

		if ( ! $first_name && ! $company ) {
			return new WP_Error( 'io_pos_invalid_customer', __( 'Poné al menos el nombre o la empresa.', 'io-punto-venta' ), array( 'status' => 400 ) );
		}

		if ( $email && ! is_email( $email ) ) {
			return new WP_Error( 'io_pos_invalid_email', __( 'El correo no es válido.', 'io-punto-venta' ), array( 'status' => 400 ) );
		}

		if ( $email && email_exists( $email ) ) {
			return new WP_Error( 'io_pos_email_exists', __( 'Ya hay un cliente con ese correo.', 'io-punto-venta' ), array( 'status' => 400 ) );
		}

		$base_login = io_pos_build_username( $first_name, $last_name, $company );
		$login      = $base_login;
		$suffix     = 1;

		while ( username_exists( $login ) ) {
			$login = $base_login . '_' . ++$suffix;
		}

		$customer_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_pass'    => wp_generate_password( 16 ),
				'user_email'   => $email ? $email : '',
				'first_name'   => $first_name,
				'last_name'    => $last_name,
				'display_name' => trim( $first_name . ' ' . $last_name ) ? trim( $first_name . ' ' . $last_name ) : $company,
				'role'         => 'customer',
			)
		);

		if ( is_wp_error( $customer_id ) ) {
			return new WP_Error( 'io_pos_customer_error', $customer_id->get_error_message(), array( 'status' => 400 ) );
		}

		$fields = array(
			'billing_first_name' => $first_name,
			'billing_last_name'  => $last_name,
			'billing_company'    => $company,
			'billing_phone'      => $phone,
			'billing_email'      => $email,
			'billing_address_1'  => sanitize_text_field( $data['address'] ?? '' ),
			'billing_city'       => sanitize_text_field( $data['city'] ?? '' ),
			'billing_vat'        => sanitize_text_field( $data['vat'] ?? '' ),
		);

		foreach ( $fields as $key => $value ) {
			if ( '' !== $value ) {
				update_user_meta( $customer_id, $key, $value );
			}
		}

		update_user_meta( $customer_id, '_io_pos_created_from_terminal', 1 );

		return rest_ensure_response( array( 'customer' => self::format_customer( $customer_id ) ) );
	}

	/**
	 * Convierte un cliente al formato del mostrador.
	 *
	 * @param int $customer_id El ID.
	 *
	 * @return array
	 */
	public static function format_customer( $customer_id ) {
		$fields = io_pos_get_customer_fields( $customer_id );

		if ( ! $fields ) {
			return array();
		}

		$name = trim( $fields['first_name'] . ' ' . $fields['last_name'] );

		return array(
			'id'      => (int) $customer_id,
			'name'    => $name ? $name : $fields['company'],
			'company' => $fields['company'],
			'phone'   => $fields['phone'],
			'email'   => $fields['email'],
			'vat'     => $fields['vat'],
		);
	}

	/* --------------------------------------------------------------------- *
	 * Pedidos
	 * --------------------------------------------------------------------- */

	/**
	 * Emite un pedido.
	 *
	 * @param WP_REST_Request $request La petición.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_order( $request ) {
		$order = IO_POS_Order_Builder::create( (array) $request->get_json_params() );

		if ( is_wp_error( $order ) ) {
			$order->add_data( array( 'status' => 400 ) );

			return $order;
		}

		return rest_ensure_response( array( 'order' => IO_POS_Order_Builder::format_order( $order ) ) );
	}

	/**
	 * Historial de ventas del mostrador.
	 *
	 * @param WP_REST_Request $request La petición.
	 *
	 * @return WP_REST_Response
	 */
	public function get_orders( $request ) {
		$search   = trim( (string) $request->get_param( 'search' ) );
		$page     = max( 1, absint( $request->get_param( 'page' ) ) );
		$filter   = sanitize_key( (string) $request->get_param( 'filter' ) );
		$per_page = 20;

		$args = array(
			'limit'      => $per_page,
			'page'       => $page,
			'orderby'    => 'date',
			'order'      => 'DESC',
			'status'     => array_keys( wc_get_order_statuses() ),
			'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => '_io_pos_order',
					'compare' => 'EXISTS',
				),
			),
		);

		if ( 'mine' === $filter ) {
			$args['meta_query'][] = array(
				'key'   => '_io_pos_cashier',
				'value' => (string) get_current_user_id(),
			);
		}

		if ( 'balance' === $filter ) {
			$args['meta_query'][] = array(
				'key'     => IO_POS_Payments::META_BALANCE,
				'value'   => 0,
				'compare' => '>',
				'type'    => 'DECIMAL(10,2)',
			);
		}

		if ( 'today' === $filter ) {
			$args['date_created'] = '>=' . strtotime( io_pos_today() . ' 00:00:00' );
		}

		if ( $search ) {
			$args = $this->apply_history_search( $args, $search );
		}

		$orders = wc_get_orders( apply_filters( 'io_pos_terminal_history_query', $args, $request ) );
		$orders = is_array( $orders ) ? $orders : array();
		$list   = array();

		foreach ( $orders as $order ) {
			$list[] = IO_POS_Order_Builder::format_order( $order, false );
		}

		return rest_ensure_response(
			array(
				'orders'   => $list,
				'page'     => $page,
				'has_more' => count( $list ) === $per_page,
			)
		);
	}

	/**
	 * Agrega la búsqueda del historial a la consulta.
	 *
	 * Un número se busca como número de pedido, que es lo que se pregunta en el
	 * mostrador. Un texto se busca por cliente: con las tablas nuevas de
	 * WooCommerce alcanza con "s"; con el guardado clásico hay que mirar los
	 * datos de facturación a mano.
	 *
	 * @param array  $args   Argumentos de la consulta.
	 * @param string $search Lo que se escribió.
	 *
	 * @return array
	 */
	protected function apply_history_search( array $args, $search ) {
		if ( is_numeric( $search ) ) {
			$order = wc_get_order( absint( $search ) );

			$args['post__in'] = $order ? array( $order->get_id() ) : array( 0 );

			return $args;
		}

		$hpos = class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		if ( $hpos ) {
			$args['s'] = $search;

			return $args;
		}

		$args['meta_query'][] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'relation' => 'OR',
			array(
				'key'     => '_billing_first_name',
				'value'   => $search,
				'compare' => 'LIKE',
			),
			array(
				'key'     => '_billing_last_name',
				'value'   => $search,
				'compare' => 'LIKE',
			),
			array(
				'key'     => '_billing_company',
				'value'   => $search,
				'compare' => 'LIKE',
			),
			array(
				'key'     => '_billing_phone',
				'value'   => $search,
				'compare' => 'LIKE',
			),
		);

		return $args;
	}

	/**
	 * Detalle de un pedido.
	 *
	 * @param WP_REST_Request $request La petición.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_order( $request ) {
		$order = io_pos_get_order( absint( $request['id'] ) );

		if ( ! $order ) {
			return new WP_Error( 'io_pos_not_found', __( 'No encontramos ese pedido.', 'io-punto-venta' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( array( 'order' => IO_POS_Order_Builder::format_order( $order ) ) );
	}

	/**
	 * Registra un cobro sobre un pedido existente.
	 *
	 * @param WP_REST_Request $request La petición.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_payment( $request ) {
		$order = io_pos_get_order( absint( $request['id'] ) );

		if ( ! $order ) {
			return new WP_Error( 'io_pos_not_found', __( 'No encontramos ese pedido.', 'io-punto-venta' ), array( 'status' => 404 ) );
		}

		$method = sanitize_key( (string) $request->get_param( 'method' ) );
		$amount = (float) $request->get_param( 'amount' );
		$balance = IO_POS_Payments::get_balance( $order );

		if ( $amount > $balance + 0.001 ) {
			return new WP_Error(
				'io_pos_amount_too_high',
				__( 'El importe supera el saldo pendiente.', 'io-punto-venta' ),
				array( 'status' => 400 )
			);
		}

		$payment = IO_POS_Payments::add_payment( $order, $method, $amount, array( 'save' => false ) );

		if ( is_wp_error( $payment ) ) {
			$payment->add_data( array( 'status' => 400 ) );

			return $payment;
		}

		$order->set_status( IO_POS_Payments::get_target_status( $order ) );
		$order->save();

		return rest_ensure_response( array( 'order' => IO_POS_Order_Builder::format_order( $order ) ) );
	}
}
