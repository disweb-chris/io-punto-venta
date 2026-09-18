<?php
/**
 * Production board.
 *
 * A single screen with every job that is still on the bench, ordered by
 * delivery date, so the shop can see what has to go out today.
 *
 * @package IO\POS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class IO_POS_Admin_Board
 */
class IO_POS_Admin_Board {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 20 );
	}

	/**
	 * Register the menu pages.
	 */
	public function add_menu() {
		$capability = apply_filters( 'io_pos_admin_capability', 'edit_shop_orders' );

		add_menu_page(
			__( 'Imprenta', 'io-punto-venta' ),
			__( 'Imprenta', 'io-punto-venta' ),
			$capability,
			'io-pos-board',
			array( $this, 'render' ),
			'dashicons-printer',
			56
		);

		add_submenu_page(
			'io-pos-board',
			__( 'Producción', 'io-punto-venta' ),
			__( 'Producción', 'io-punto-venta' ),
			$capability,
			'io-pos-board',
			array( $this, 'render' )
		);
	}

	/**
	 * Available views of the board.
	 *
	 * @return array<string,string>
	 */
	public static function get_views() {
		return array(
			'pending'   => __( 'Pendientes', 'io-punto-venta' ),
			'overdue'   => __( 'Vencidos', 'io-punto-venta' ),
			'today'     => __( 'Entregas de hoy', 'io-punto-venta' ),
			'week'      => __( 'Próximos 7 días', 'io-punto-venta' ),
			'delivered' => __( 'Entregados', 'io-punto-venta' ),
			'all'       => __( 'Todos', 'io-punto-venta' ),
		);
	}

	/**
	 * Query the jobs matching the current view.
	 *
	 * @param string $view   The current view.
	 * @param string $status Production status filter.
	 *
	 * @return WC_Order[]
	 */
	protected function get_jobs( $view, $status ) {
		$done       = IO_POS_Job::get_done_production_status();
		$meta_query = array(
			array(
				'key'     => IO_POS_Job::META_STATUS,
				'compare' => 'EXISTS',
			),
		);

		if ( $status && array_key_exists( $status, IO_POS_Job::get_production_statuses() ) ) {
			$meta_query[] = array(
				'key'     => IO_POS_Job::META_STATUS,
				'value'   => $status,
				'compare' => '=',
			);
		}

		switch ( $view ) {
			case 'pending':
				if ( $done ) {
					$meta_query[] = array(
						'key'     => IO_POS_Job::META_STATUS,
						'value'   => $done,
						'compare' => '!=',
					);
				}
				break;
			case 'delivered':
				if ( $done ) {
					$meta_query[] = array(
						'key'     => IO_POS_Job::META_STATUS,
						'value'   => $done,
						'compare' => '=',
					);
				}
				break;
			case 'overdue':
			case 'today':
			case 'week':
				$clause = IO_POS_Admin_Orders::get_delivery_meta_clause( $view );

				if ( $clause ) {
					$meta_query[] = $clause;
				}

				if ( 'overdue' === $view && $done ) {
					$meta_query[] = array(
						'key'     => IO_POS_Job::META_STATUS,
						'value'   => $done,
						'compare' => '!=',
					);
				}
				break;
		}

		$args = array(
			'limit'      => 200,
			'status'     => array_keys( wc_get_order_statuses() ),
			'meta_query' => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'orderby'    => 'date',
			'order'      => 'DESC',
		);

		/**
		 * Filter the query used by the production board.
		 *
		 * @param array  $args The query args.
		 * @param string $view The current view.
		 */
		$args = apply_filters( 'io_pos_board_query_args', $args, $view );

		$orders = wc_get_orders( $args );
		$orders = is_array( $orders ) ? $orders : array();

		// El orden por meta no se comporta igual en las dos formas de guardar
		// pedidos de WooCommerce, así que ordenamos acá: son pocos registros y
		// el resultado es siempre el mismo.
		usort(
			$orders,
			function ( $a, $b ) {
				$date_a = IO_POS_Job::get_delivery_date( $a );
				$date_b = IO_POS_Job::get_delivery_date( $b );

				if ( $date_a === $date_b ) {
					return $b->get_id() <=> $a->get_id();
				}

				// Los trabajos sin fecha van al final.
				if ( ! $date_a ) {
					return 1;
				}

				if ( ! $date_b ) {
					return -1;
				}

				return strcmp( $date_a, $date_b );
			}
		);

		return $orders;
	}

	/**
	 * Render the board.
	 */
	public function render() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$views  = self::get_views();
		$view   = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'pending';
		$view   = array_key_exists( $view, $views ) ? $view : 'pending';
		$status = isset( $_GET['production_status'] ) ? sanitize_key( wp_unslash( $_GET['production_status'] ) ) : '';
		// phpcs:enable

		$jobs = $this->get_jobs( $view, $status );

		echo '<div class="wrap io-pos-board">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Producción', 'io-punto-venta' ) . '</h1>';

		echo '<ul class="subsubsub">';
		$links = array();

		foreach ( $views as $key => $label ) {
			$url     = add_query_arg(
				array(
					'page' => 'io-pos-board',
					'view' => $key,
				),
				admin_url( 'admin.php' )
			);
			$links[] = sprintf(
				'<li><a href="%1$s"%2$s>%3$s</a></li>',
				esc_url( $url ),
				$key === $view ? ' class="current"' : '',
				esc_html( $label )
			);
		}

		echo wp_kses_post( implode( ' | ', $links ) );
		echo '</ul>';

		echo '<form method="get" class="io-pos-board__filters">';
		echo '<input type="hidden" name="page" value="io-pos-board" />';
		printf( '<input type="hidden" name="view" value="%s" />', esc_attr( $view ) );

		echo '<select name="production_status">';
		printf( '<option value="">%s</option>', esc_html__( 'Todos los estados', 'io-punto-venta' ) );

		foreach ( IO_POS_Job::get_production_statuses() as $key => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $key ),
				selected( $key, $status, false ),
				esc_html( $label )
			);
		}

		echo '</select> ';
		submit_button( __( 'Filtrar', 'io-punto-venta' ), '', '', false );
		echo '</form>';

		if ( ! $jobs ) {
			echo '<p class="io-pos-empty">' . esc_html__( 'No hay trabajos para mostrar con este filtro.', 'io-punto-venta' ) . '</p>';
			echo '</div>';

			return;
		}

		echo '<table class="wp-list-table widefat fixed striped io-pos-board__table">';
		echo '<thead><tr>';
		printf( '<th class="column-order">%s</th>', esc_html__( 'Pedido', 'io-punto-venta' ) );
		printf( '<th class="column-customer">%s</th>', esc_html__( 'Cliente', 'io-punto-venta' ) );
		printf( '<th class="column-items">%s</th>', esc_html__( 'Trabajo', 'io-punto-venta' ) );
		printf( '<th class="column-delivery">%s</th>', esc_html__( 'Entrega', 'io-punto-venta' ) );
		printf( '<th class="column-production">%s</th>', esc_html__( 'Estado', 'io-punto-venta' ) );
		printf( '<th class="column-total">%s</th>', esc_html__( 'Total / saldo', 'io-punto-venta' ) );
		echo '</tr></thead><tbody>';

		$current_group = null;

		foreach ( $jobs as $order ) {
			$date  = IO_POS_Job::get_delivery_date( $order );
			$group = $date ? io_pos_format_date( $date ) : __( 'Sin fecha de entrega', 'io-punto-venta' );

			if ( $group !== $current_group ) {
				$current_group = $group;

				printf(
					'<tr class="io-pos-board__group"><td colspan="6"><strong>%s</strong></td></tr>',
					esc_html( $group )
				);
			}

			$this->render_row( $order );
		}

		echo '</tbody></table></div>';
	}

	/**
	 * Render a single job row.
	 *
	 * @param WC_Order $order The order.
	 */
	protected function render_row( $order ) {
		$balance = io_pos_get_balance_due( $order );

		echo '<tr>';

		printf(
			'<td class="column-order"><a href="%1$s"><strong>#%2$s</strong></a><br><small>%3$s</small></td>',
			esc_url( $order->get_edit_order_url() ),
			esc_html( $order->get_order_number() ),
			esc_html( wc_get_order_status_name( $order->get_status() ) )
		);

		printf(
			'<td class="column-customer">%1$s<br><small>%2$s</small></td>',
			esc_html( trim( $order->get_formatted_billing_full_name() ) ? $order->get_formatted_billing_full_name() : __( 'Consumidor final', 'io-punto-venta' ) ),
			esc_html( $order->get_billing_phone() )
		);

		echo '<td class="column-items">';
		echo wp_kses_post( $this->get_items_summary( $order ) );
		echo '</td>';

		printf( '<td class="column-delivery">%s</td>', wp_kses_post( IO_POS_Admin_Orders::get_delivery_badge( $order ) ) );

		printf(
			'<td class="column-production">%s</td>',
			wp_kses(
				IO_POS_Admin_Orders::get_production_control( $order ),
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
			)
		);

		echo '<td class="column-total">';
		echo wp_kses_post( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) );

		if ( $balance > 0 ) {
			printf(
				'<br><small class="io-pos-hint io-pos-hint--overdue">%1$s %2$s</small>',
				esc_html__( 'Saldo:', 'io-punto-venta' ),
				wp_kses_post( wc_price( $balance, array( 'currency' => $order->get_currency() ) ) )
			);
		}

		echo '</td>';
		echo '</tr>';
	}

	/**
	 * Build a short summary of what the job is about.
	 *
	 * @param WC_Order $order The order.
	 *
	 * @return string
	 */
	protected function get_items_summary( $order ) {
		$lines = array();

		foreach ( $order->get_items() as $item ) {
			$lines[] = sprintf( '%1$d × %2$s', (int) $item->get_quantity(), esc_html( $item->get_name() ) );

			$note = $item->get_meta( 'yith_pos_order_item_note' );

			if ( $note ) {
				$lines[] = '<small class="io-pos-hint">' . esc_html( $note ) . '</small>';
			}

			if ( count( $lines ) > 6 ) {
				$lines[] = '…';
				break;
			}
		}

		$details = IO_POS_Job::get_job_details( $order );

		foreach ( array( 'notes', 'priority' ) as $key ) {
			if ( isset( $details[ $key ] ) ) {
				$lines[] = sprintf(
					'<small class="io-pos-hint"><strong>%1$s:</strong> %2$s</small>',
					esc_html( $details[ $key ]['label'] ),
					esc_html( $details[ $key ]['formatted'] )
				);
			}
		}

		return implode( '<br>', $lines );
	}
}
