<?php
/**
 * Orders screen integration.
 *
 * Adds the delivery date and the production status to the order list (both
 * with the legacy posts table and with HPOS), a metabox on the order edit
 * screen and the filters a print shop needs to find pending work.
 *
 * @package IO\POS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class IO_POS_Admin_Orders
 */
class IO_POS_Admin_Orders {

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Columns.
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_columns' ), 20 );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_columns' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_column' ), 20, 2 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_column' ), 20, 2 );

		// Sorting.
		add_filter( 'manage_edit-shop_order_sortable_columns', array( $this, 'add_sortable_columns' ) );
		add_filter( 'manage_woocommerce_page_wc-orders_sortable_columns', array( $this, 'add_sortable_columns' ) );

		// Filters.
		add_action( 'restrict_manage_posts', array( $this, 'render_filters_legacy' ), 20 );
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( $this, 'render_filters' ), 20 );

		// Queries.
		add_filter( 'request', array( $this, 'filter_legacy_request' ) );
		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( $this, 'filter_hpos_query' ) );

		// Metabox.
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ), 40, 2 );
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save_meta_box' ), 40, 2 );

		// Bulk actions.
		add_filter( 'bulk_actions-edit-shop_order', array( $this, 'add_bulk_actions' ) );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( $this, 'add_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-shop_order', array( $this, 'handle_bulk_actions' ), 10, 3 );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( $this, 'handle_bulk_actions' ), 10, 3 );

		// Assets and AJAX.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_io_pos_set_production_status', array( $this, 'ajax_set_production_status' ) );
	}

	/**
	 * Whether the current screen is one of the order list tables.
	 *
	 * @return bool
	 */
	public static function is_orders_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen ) {
			return false;
		}

		return in_array( $screen->id, array( 'edit-shop_order', 'woocommerce_page_wc-orders' ), true );
	}

	/**
	 * Whether the current screen is the order edit screen.
	 *
	 * @return bool
	 */
	public static function is_order_edit_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen ) {
			return false;
		}

		return in_array( $screen->id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true );
	}

	/**
	 * Add the job columns to the orders table.
	 *
	 * @param array $columns Existing columns.
	 *
	 * @return array
	 */
	public function add_columns( $columns ) {
		if ( ! is_array( $columns ) ) {
			return $columns;
		}

		$new = array();

		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;

			if ( 'order_status' === $key ) {
				$new = array_merge( $new, $this->get_extra_columns() );
			}
		}

		// Si no está la columna de estado, las agregamos al final.
		if ( ! isset( $new['io_pos_delivery'] ) ) {
			$new = array_merge( $new, $this->get_extra_columns() );
		}

		return $new;
	}

	/**
	 * Columnas que agrega el plugin.
	 *
	 * @return array<string,string>
	 */
	protected function get_extra_columns() {
		$columns = array(
			'io_pos_delivery' => __( 'Entrega', 'io-punto-venta' ),
		);

		if ( IO_POS_Settings::is_enabled( 'production_enabled' ) ) {
			$columns['io_pos_production'] = __( 'Producción', 'io-punto-venta' );
		}

		$columns['io_pos_payments'] = __( 'Cobrado', 'io-punto-venta' );

		return $columns;
	}

	/**
	 * Make the delivery column sortable.
	 *
	 * @param array $columns Sortable columns.
	 *
	 * @return array
	 */
	public function add_sortable_columns( $columns ) {
		$columns['io_pos_delivery'] = 'io_pos_delivery';

		return $columns;
	}

	/**
	 * Render the content of our columns.
	 *
	 * @param string       $column The column key.
	 * @param int|WC_Order $order  Order ID (legacy) or order object (HPOS).
	 */
	public function render_column( $column, $order ) {
		if ( ! in_array( $column, array( 'io_pos_delivery', 'io_pos_production', 'io_pos_payments' ), true ) ) {
			return;
		}

		$order = io_pos_get_order( $order );

		if ( ! $order ) {
			return;
		}

		if ( 'io_pos_delivery' === $column ) {
			echo wp_kses_post( self::get_delivery_badge( $order ) );

			return;
		}

		if ( 'io_pos_payments' === $column ) {
			echo wp_kses_post( self::get_payments_badge( $order ) );

			return;
		}

		echo wp_kses(
			self::get_production_control( $order ),
			array(
				'select' => array(
					'class'         => true,
					'data-order-id' => true,
					'aria-label'    => true,
				),
				'option' => array(
					'value'    => true,
					'selected' => true,
				),
				'span'   => array( 'class' => true ),
			)
		);
	}

	/**
	 * Get the HTML of the delivery date badge.
	 *
	 * @param WC_Order $order The order.
	 *
	 * @return string
	 */
	public static function get_delivery_badge( $order ) {
		$date = IO_POS_Job::get_delivery_date( $order );

		if ( ! $date ) {
			return '<span class="io-pos-badge io-pos-badge--none">&ndash;</span>';
		}

		$state = io_pos_delivery_state( $date );
		$days  = io_pos_days_until( $date );
		$done  = IO_POS_Job::get_done_production_status();

		if ( $done && $done === $order->get_meta( IO_POS_Job::META_STATUS ) ) {
			$state = 'done';
		}

		$hints = array(
			'overdue'   => __( 'Vencido', 'io-punto-venta' ),
			'today'     => __( 'Hoy', 'io-punto-venta' ),
			'soon'      => 1 === $days ? __( 'Mañana', 'io-punto-venta' ) : sprintf( /* translators: %d: number of days. */ __( 'En %d días', 'io-punto-venta' ), (int) $days ),
			'scheduled' => '',
			'done'      => __( 'Entregado', 'io-punto-venta' ),
		);

		$hint = $hints[ $state ] ?? '';
		$time = (string) $order->get_meta( IO_POS_Job::META_DELIVERY_TIME );

		$html = sprintf(
			'<span class="io-pos-badge io-pos-badge--%1$s">%2$s</span>',
			esc_attr( $state ),
			esc_html( io_pos_format_date( $date ) )
		);

		if ( $hint ) {
			$html .= sprintf( '<br><small class="io-pos-hint io-pos-hint--%1$s">%2$s</small>', esc_attr( $state ), esc_html( $hint ) );
		}

		if ( $time ) {
			$html .= sprintf( '<br><small class="io-pos-hint">%s</small>', esc_html( $time ) );
		}

		return $html;
	}

	/**
	 * Resumen de lo cobrado de un pedido.
	 *
	 * @param WC_Order $order El pedido.
	 *
	 * @return string
	 */
	public static function get_payments_badge( $order ) {
		if ( ! io_pos_tracks_payments( $order ) ) {
			return '<span class="io-pos-badge io-pos-badge--none">&ndash;</span>';
		}

		$balance  = IO_POS_Payments::get_balance( $order );
		$paid     = IO_POS_Payments::get_paid_total( $order );
		$currency = $order->get_currency();

		if ( $balance <= 0 ) {
			return sprintf(
				'<span class="io-pos-badge io-pos-badge--done">%s</span>',
				esc_html( io_pos_format_price( $paid, $currency ) )
			);
		}

		return sprintf(
			'<span class="io-pos-badge io-pos-badge--today">%1$s</span><br><small class="io-pos-hint io-pos-hint--overdue">%2$s %3$s</small>',
			esc_html( io_pos_format_price( $paid, $currency ) ),
			esc_html__( 'Saldo:', 'io-punto-venta' ),
			esc_html( io_pos_format_price( $balance, $currency ) )
		);
	}

	/**
	 * Get the production status select used in the list table.
	 *
	 * @param WC_Order $order The order.
	 *
	 * @return string
	 */
	public static function get_production_control( $order ) {
		if ( ! io_pos_is_job_order( $order ) ) {
			return '<span class="io-pos-badge io-pos-badge--none">&ndash;</span>';
		}

		$current  = IO_POS_Job::get_production_status( $order );
		$statuses = IO_POS_Job::get_production_statuses();

		$html = sprintf(
			'<select class="io-pos-status-select" data-order-id="%1$d" aria-label="%2$s">',
			(int) $order->get_id(),
			esc_attr__( 'Estado de producción', 'io-punto-venta' )
		);

		foreach ( $statuses as $key => $label ) {
			$html .= sprintf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $key ),
				selected( $key, $current, false ),
				esc_html( $label )
			);
		}

		$html .= '</select>';

		return $html;
	}

	/**
	 * Render the filters on the legacy orders table.
	 *
	 * @param string $post_type The current post type.
	 */
	public function render_filters_legacy( $post_type ) {
		if ( 'shop_order' !== $post_type ) {
			return;
		}

		$this->render_filters();
	}

	/**
	 * Render the delivery and production filters.
	 */
	public function render_filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$delivery   = isset( $_GET['io_pos_delivery_filter'] ) ? sanitize_key( wp_unslash( $_GET['io_pos_delivery_filter'] ) ) : '';
		$production = isset( $_GET['io_pos_production_filter'] ) ? sanitize_key( wp_unslash( $_GET['io_pos_production_filter'] ) ) : '';
		// phpcs:enable

		echo '<select name="io_pos_delivery_filter" id="io_pos_delivery_filter">';
		printf( '<option value="">%s</option>', esc_html__( 'Entrega: todas', 'io-punto-venta' ) );

		foreach ( self::get_delivery_filters() as $key => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $key ),
				selected( $key, $delivery, false ),
				esc_html( $label )
			);
		}

		echo '</select>';

		if ( ! IO_POS_Settings::is_enabled( 'production_enabled' ) ) {
			return;
		}

		echo '<select name="io_pos_production_filter" id="io_pos_production_filter">';
		printf( '<option value="">%s</option>', esc_html__( 'Producción: todos', 'io-punto-venta' ) );

		foreach ( IO_POS_Job::get_production_statuses() as $key => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $key ),
				selected( $key, $production, false ),
				esc_html( $label )
			);
		}

		echo '</select>';
	}

	/**
	 * Available delivery date filters.
	 *
	 * @return array<string,string>
	 */
	public static function get_delivery_filters() {
		return array(
			'overdue'   => __( 'Entrega vencida', 'io-punto-venta' ),
			'today'     => __( 'Entrega hoy', 'io-punto-venta' ),
			'tomorrow'  => __( 'Entrega mañana', 'io-punto-venta' ),
			'week'      => __( 'Entrega esta semana', 'io-punto-venta' ),
			'scheduled' => __( 'Con fecha de entrega', 'io-punto-venta' ),
			'none'      => __( 'Sin fecha de entrega', 'io-punto-venta' ),
		);
	}

	/**
	 * Build the meta query matching the current filters.
	 *
	 * @return array
	 */
	protected function get_filters_meta_query() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$delivery   = isset( $_GET['io_pos_delivery_filter'] ) ? sanitize_key( wp_unslash( $_GET['io_pos_delivery_filter'] ) ) : '';
		$production = isset( $_GET['io_pos_production_filter'] ) ? sanitize_key( wp_unslash( $_GET['io_pos_production_filter'] ) ) : '';
		// phpcs:enable

		$meta_query = array();

		if ( $production && array_key_exists( $production, IO_POS_Job::get_production_statuses() ) ) {
			$meta_query[] = array(
				'key'     => IO_POS_Job::META_STATUS,
				'value'   => $production,
				'compare' => '=',
			);
		}

		$delivery_clause = self::get_delivery_meta_clause( $delivery );

		if ( $delivery_clause ) {
			$meta_query[] = $delivery_clause;
		}

		return $meta_query;
	}

	/**
	 * Get the meta clause for a delivery filter.
	 *
	 * @param string $filter Filter key.
	 *
	 * @return array|null
	 */
	public static function get_delivery_meta_clause( $filter ) {
		$today = io_pos_today();

		switch ( $filter ) {
			case 'overdue':
				return array(
					'key'     => IO_POS_Job::META_DELIVERY_DATE,
					'value'   => $today,
					'compare' => '<',
					'type'    => 'DATE',
				);
			case 'today':
				return array(
					'key'     => IO_POS_Job::META_DELIVERY_DATE,
					'value'   => $today,
					'compare' => '=',
					'type'    => 'DATE',
				);
			case 'tomorrow':
				return array(
					'key'     => IO_POS_Job::META_DELIVERY_DATE,
					'value'   => gmdate( 'Y-m-d', strtotime( $today . ' +1 day' ) ),
					'compare' => '=',
					'type'    => 'DATE',
				);
			case 'week':
				return array(
					'key'     => IO_POS_Job::META_DELIVERY_DATE,
					'value'   => array( $today, gmdate( 'Y-m-d', strtotime( $today . ' +7 days' ) ) ),
					'compare' => 'BETWEEN',
					'type'    => 'DATE',
				);
			case 'scheduled':
				return array(
					'key'     => IO_POS_Job::META_DELIVERY_DATE,
					'value'   => '',
					'compare' => '!=',
				);
			case 'none':
				return array(
					'relation' => 'OR',
					array(
						'key'     => IO_POS_Job::META_DELIVERY_DATE,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => IO_POS_Job::META_DELIVERY_DATE,
						'value'   => '',
						'compare' => '=',
					),
				);
		}

		return null;
	}

	/**
	 * Apply the filters and the sorting to the legacy orders table.
	 *
	 * @param array $vars Query vars.
	 *
	 * @return array
	 */
	public function filter_legacy_request( $vars ) {
		if ( ! is_admin() || ! isset( $vars['post_type'] ) || 'shop_order' !== $vars['post_type'] ) {
			return $vars;
		}

		$meta_query = $this->get_filters_meta_query();

		if ( $meta_query ) {
			$vars['meta_query'] = array_merge( (array) ( $vars['meta_query'] ?? array() ), $meta_query ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		if ( isset( $vars['orderby'] ) && 'io_pos_delivery' === $vars['orderby'] ) {
			$vars['meta_key'] = IO_POS_Job::META_DELIVERY_DATE; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$vars['orderby']  = 'meta_value';
		}

		return $vars;
	}

	/**
	 * Apply the filters and the sorting to the HPOS orders table.
	 *
	 * @param array $args Query args.
	 *
	 * @return array
	 */
	public function filter_hpos_query( $args ) {
		$meta_query = $this->get_filters_meta_query();

		if ( $meta_query ) {
			$args['meta_query'] = array_merge( (array) ( $args['meta_query'] ?? array() ), $meta_query ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		if ( isset( $args['orderby'] ) && 'io_pos_delivery' === $args['orderby'] ) {
			// HPOS ignores the classic meta_key/meta_value pair and orders by
			// named meta_query clauses instead.
			$direction = strtoupper( (string) ( $args['order'] ?? 'ASC' ) );
			$direction = 'DESC' === $direction ? 'DESC' : 'ASC';

			$args['meta_query']['io_pos_delivery_sort'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'key'     => IO_POS_Job::META_DELIVERY_DATE,
				'compare' => 'EXISTS',
			);

			$args['meta_key'] = IO_POS_Job::META_DELIVERY_DATE; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$args['orderby']  = array( 'io_pos_delivery_sort' => $direction );

			unset( $args['order'] );
		}

		return $args;
	}

	/**
	 * Register the job metabox.
	 *
	 * @param string $screen_id The screen ID.
	 * @param mixed  $order     The order or post being edited.
	 */
	public function add_meta_box( $screen_id, $order = null ) {
		$screens = array( 'shop_order', 'woocommerce_page_wc-orders' );

		if ( ! in_array( $screen_id, $screens, true ) ) {
			return;
		}

		add_meta_box(
			'io-pos-job',
			__( 'Trabajo de imprenta', 'io-punto-venta' ),
			array( $this, 'render_meta_box' ),
			$screen_id,
			'side',
			'high'
		);
	}

	/**
	 * Render the job metabox.
	 *
	 * @param mixed $post_or_order The post or order being edited.
	 */
	public function render_meta_box( $post_or_order ) {
		$order = io_pos_get_order( $post_or_order instanceof WP_Post ? $post_or_order->ID : $post_or_order );

		if ( ! $order ) {
			return;
		}

		wp_nonce_field( 'io_pos_save_job', 'io_pos_job_nonce' );

		echo '<div class="io-pos-metabox">';

		foreach ( IO_POS_Job::get_schema() as $key => $field ) {
			$value = (string) $order->get_meta( $field['meta_key'] );
			$name  = 'io_pos_job[' . $key . ']';
			$id    = 'io-pos-job-' . $key;

			printf( '<p class="form-field io-pos-field io-pos-field--%1$s">', esc_attr( $field['type'] ) );
			printf( '<label for="%1$s"><strong>%2$s</strong></label>', esc_attr( $id ), esc_html( $field['label'] ) );

			switch ( $field['type'] ) {
				case 'textarea':
					printf(
						'<textarea id="%1$s" name="%2$s" rows="3" class="widefat">%3$s</textarea>',
						esc_attr( $id ),
						esc_attr( $name ),
						esc_textarea( $value )
					);
					break;
				case 'select':
					printf( '<select id="%1$s" name="%2$s" class="widefat">', esc_attr( $id ), esc_attr( $name ) );
					printf( '<option value="">%s</option>', esc_html__( 'Elegir…', 'io-punto-venta' ) );

					foreach ( $field['options'] as $option ) {
						printf(
							'<option value="%1$s"%2$s>%3$s</option>',
							esc_attr( $option ),
							selected( $option, $value, false ),
							esc_html( $option )
						);
					}

					echo '</select>';
					break;
				case 'date':
					printf(
						'<input type="date" id="%1$s" name="%2$s" value="%3$s" class="widefat" />',
						esc_attr( $id ),
						esc_attr( $name ),
						esc_attr( io_pos_normalize_date( $value ) )
					);
					break;
				case 'number':
					printf(
						'<input type="number" step="any" id="%1$s" name="%2$s" value="%3$s" class="widefat" />',
						esc_attr( $id ),
						esc_attr( $name ),
						esc_attr( $value )
					);
					break;
				default:
					printf(
						'<input type="text" id="%1$s" name="%2$s" value="%3$s" class="widefat" />',
						esc_attr( $id ),
						esc_attr( $name ),
						esc_attr( $value )
					);
					break;
			}

			echo '</p>';
		}

		if ( IO_POS_Settings::is_enabled( 'production_enabled' ) ) {
			$current = IO_POS_Job::get_production_status( $order );

			echo '<p class="form-field io-pos-field io-pos-field--status">';
			printf( '<label for="io-pos-production-status"><strong>%s</strong></label>', esc_html__( 'Estado de producción', 'io-punto-venta' ) );
			echo '<select id="io-pos-production-status" name="io_pos_production_status" class="widefat">';

			foreach ( IO_POS_Job::get_production_statuses() as $status_key => $label ) {
				printf(
					'<option value="%1$s"%2$s>%3$s</option>',
					esc_attr( $status_key ),
					selected( $status_key, $current, false ),
					esc_html( $label )
				);
			}

			echo '</select></p>';
		}

		echo '</div>';

		$this->render_payments_box( $order );
	}

	/**
	 * Dibuja el bloque de cobros dentro de la caja del trabajo.
	 *
	 * @param WC_Order $order El pedido.
	 */
	protected function render_payments_box( $order ) {
		$payments = IO_POS_Payments::get_payments( $order );
		$balance  = IO_POS_Payments::get_balance( $order );
		$paid     = IO_POS_Payments::get_paid_total( $order );
		$currency = $order->get_currency();

		if ( ! io_pos_tracks_payments( $order ) ) {
			return;
		}

		echo '<div class="io-pos-metabox io-pos-metabox--payments">';
		printf( '<h4>%s</h4>', esc_html__( 'Cobros', 'io-punto-venta' ) );

		if ( $payments ) {
			echo '<ul class="io-pos-payments">';

			foreach ( $payments as $payment ) {
				printf(
					'<li><strong>%1$s</strong> · %2$s<br><small>%3$s</small></li>',
					esc_html( io_pos_format_price( $payment['amount'], $currency ) ),
					esc_html( IO_POS_Payments::get_method_label( $payment['method'] ) ),
					esc_html( mysql2date( get_option( 'date_format' ) . ' H:i', $payment['date'] ?? '' ) )
				);
			}

			echo '</ul>';
		} else {
			printf( '<p class="io-pos-hint">%s</p>', esc_html__( 'Todavía no se cobró nada.', 'io-punto-venta' ) );
		}

		printf(
			'<p><strong>%1$s</strong> %2$s</p>',
			esc_html__( 'Cobrado:', 'io-punto-venta' ),
			esc_html( io_pos_format_price( $paid, $currency ) )
		);

		if ( $balance > 0 ) {
			printf(
				'<p class="io-pos-balance"><strong>%1$s</strong> %2$s</p>',
				esc_html__( 'Saldo pendiente:', 'io-punto-venta' ),
				esc_html( io_pos_format_price( $balance, $currency ) )
			);

			if ( current_user_can( 'io_pos_collect_balance' ) ) {
				echo '<div class="io-pos-add-payment">';
				printf( '<label for="io-pos-payment-amount"><strong>%s</strong></label>', esc_html__( 'Registrar un cobro', 'io-punto-venta' ) );

				echo '<select name="io_pos_new_payment[method]" class="widefat">';

				foreach ( IO_POS_Payments::get_methods() as $key => $label ) {
					printf(
						'<option value="%1$s"%2$s>%3$s</option>',
						esc_attr( $key ),
						selected( $key, IO_POS_Payments::get_cash_method(), false ),
						esc_html( $label )
					);
				}

				echo '</select>';

				printf(
					'<input type="number" step="0.01" min="0" max="%1$s" id="io-pos-payment-amount" name="io_pos_new_payment[amount]" class="widefat" placeholder="%2$s" />',
					esc_attr( (string) $balance ),
					esc_attr( (string) $balance )
				);

				printf( '<p class="io-pos-hint">%s</p>', esc_html__( 'Se registra al guardar el pedido.', 'io-punto-venta' ) );
				echo '</div>';
			}
		}

		echo '</div>';
	}

	/**
	 * Save the job metabox.
	 *
	 * @param int   $order_id The order ID.
	 * @param mixed $order    The order.
	 */
	public function save_meta_box( $order_id, $order = null ) {
		if ( ! isset( $_POST['io_pos_job_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['io_pos_job_nonce'] ) ), 'io_pos_save_job' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}

		$order = io_pos_get_order( $order ? $order : $order_id );

		if ( ! $order ) {
			return;
		}

		$raw = isset( $_POST['io_pos_job'] ) ? wp_unslash( $_POST['io_pos_job'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw = is_array( $raw ) ? $raw : array();

		foreach ( IO_POS_Job::get_schema() as $key => $field ) {
			if ( ! array_key_exists( $key, $raw ) ) {
				continue;
			}

			$value = is_scalar( $raw[ $key ] ) ? (string) $raw[ $key ] : '';

			if ( '' === trim( $value ) ) {
				$order->delete_meta_data( $field['meta_key'] );
				continue;
			}

			$order->update_meta_data( $field['meta_key'], $value );
		}

		if ( isset( $_POST['io_pos_production_status'] ) ) {
			$status = sanitize_key( wp_unslash( $_POST['io_pos_production_status'] ) );

			$this->update_production_status( $order, $status, false );
		}

		$order->save();

		IO_POS_Job::sanitize_order_meta( $order );

		$this->maybe_add_payment( $order );
	}

	/**
	 * Registra el cobro cargado en la caja del trabajo, si lo hay.
	 *
	 * @param WC_Order $order El pedido.
	 */
	protected function maybe_add_payment( $order ) {
		if ( ! isset( $_POST['io_pos_new_payment'] ) || ! current_user_can( 'io_pos_collect_balance' ) ) {
			return;
		}

		$raw    = (array) wp_unslash( $_POST['io_pos_new_payment'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$amount = round( (float) str_replace( ',', '.', (string) ( $raw['amount'] ?? 0 ) ), wc_get_price_decimals() );

		if ( $amount <= 0 ) {
			return;
		}

		$balance = IO_POS_Payments::get_balance( $order );

		if ( $amount > $balance + 0.01 ) {
			$amount = $balance;
		}

		if ( $amount <= 0 ) {
			return;
		}

		$payment = IO_POS_Payments::add_payment(
			$order,
			sanitize_key( $raw['method'] ?? '' ),
			$amount,
			array( 'save' => false )
		);

		if ( is_wp_error( $payment ) ) {
			return;
		}

		$order->set_status( IO_POS_Payments::get_target_status( $order ) );
		$order->save();
	}

	/**
	 * Update the production status of an order.
	 *
	 * @param WC_Order $order  The order.
	 * @param string   $status The new status key.
	 * @param bool     $save   Whether to save the order.
	 *
	 * @return bool Whether the status changed.
	 */
	public function update_production_status( $order, $status, $save = true ) {
		$statuses = IO_POS_Job::get_production_statuses();

		if ( ! array_key_exists( $status, $statuses ) ) {
			return false;
		}

		$previous = (string) $order->get_meta( IO_POS_Job::META_STATUS );

		if ( $previous === $status ) {
			return false;
		}

		$order->update_meta_data( IO_POS_Job::META_STATUS, $status );

		$order->add_order_note(
			sprintf(
				/* translators: 1: previous status, 2: new status. */
				__( 'Estado de producción: %1$s → %2$s.', 'io-punto-venta' ),
				$previous ? IO_POS_Job::get_production_status_label( $previous ) : __( 'sin estado', 'io-punto-venta' ),
				$statuses[ $status ]
			)
		);

		$done = IO_POS_Job::get_done_production_status();

		if ( $done && $done === $status && IO_POS_Settings::is_enabled( 'production_complete_order_on_done' ) ) {
			if ( ! $order->has_status( array( 'completed', 'cancelled', 'refunded' ) ) && 0 >= io_pos_get_balance_due( $order ) ) {
				$order->set_status( 'completed', __( 'Trabajo entregado desde el panel de producción.', 'io-punto-venta' ) );
			}
		}

		if ( $save ) {
			$order->save();
		}

		/**
		 * Fires when the production status of an order changes.
		 *
		 * @param WC_Order $order    The order.
		 * @param string   $status   The new status.
		 * @param string   $previous The previous status.
		 */
		do_action( 'io_pos_production_status_changed', $order, $status, $previous );

		return true;
	}

	/**
	 * Add the bulk actions.
	 *
	 * @param array $actions Existing actions.
	 *
	 * @return array
	 */
	public function add_bulk_actions( $actions ) {
		if ( ! IO_POS_Settings::is_enabled( 'production_enabled' ) ) {
			return $actions;
		}

		foreach ( IO_POS_Job::get_production_statuses() as $key => $label ) {
			$actions[ 'io_pos_status_' . $key ] = sprintf(
				/* translators: %s: production status label. */
				__( 'Producción: marcar como %s', 'io-punto-venta' ),
				$label
			);
		}

		return $actions;
	}

	/**
	 * Handle the bulk actions.
	 *
	 * @param string $redirect_to Redirect URL.
	 * @param string $action      The action.
	 * @param array  $ids         Selected order IDs.
	 *
	 * @return string
	 */
	public function handle_bulk_actions( $redirect_to, $action, $ids ) {
		if ( 0 !== strpos( $action, 'io_pos_status_' ) ) {
			return $redirect_to;
		}

		$status  = substr( $action, strlen( 'io_pos_status_' ) );
		$changed = 0;

		foreach ( (array) $ids as $id ) {
			$order = io_pos_get_order( $id );

			if ( $order && $this->update_production_status( $order, $status ) ) {
				++$changed;
			}
		}

		return add_query_arg( 'io_pos_bulk_changed', $changed, $redirect_to );
	}

	/**
	 * Enqueue the admin assets.
	 *
	 * @param string $hook The current admin page.
	 */
	public function enqueue_assets( $hook ) {
		if ( ! self::is_orders_screen() && ! self::is_order_edit_screen() && 'imprenta_page_io-pos-board' !== $hook && 'toplevel_page_io-pos-board' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'io-pos-admin', IO_POS_ASSETS_URL . 'css/admin.css', array(), IO_POS_VERSION );
		wp_enqueue_script( 'io-pos-admin', IO_POS_ASSETS_URL . 'js/admin.js', array( 'jquery' ), IO_POS_VERSION, true );

		wp_localize_script(
			'io-pos-admin',
			'ioPosAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'io_pos_admin' ),
				'saving'  => __( 'Guardando…', 'io-punto-venta' ),
				'saved'   => __( 'Guardado', 'io-punto-venta' ),
				'error'   => __( 'No se pudo guardar el estado.', 'io-punto-venta' ),
			)
		);
	}

	/**
	 * Change the production status of an order through AJAX.
	 */
	public function ajax_set_production_status() {
		check_ajax_referer( 'io_pos_admin', 'nonce' );

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'io-punto-venta' ) ), 403 );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$status   = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		$order    = io_pos_get_order( $order_id );

		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Pedido no encontrado.', 'io-punto-venta' ) ), 404 );
		}

		if ( ! $this->update_production_status( $order, $status ) ) {
			wp_send_json_error( array( 'message' => __( 'Estado no válido.', 'io-punto-venta' ) ), 400 );
		}

		wp_send_json_success(
			array(
				'status' => $status,
				'label'  => IO_POS_Job::get_production_status_label( $status ),
			)
		);
	}
}
