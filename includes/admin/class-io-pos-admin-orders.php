<?php
/**
 * Pantalla de pedidos.
 *
 * Agrega solo lo que no cubre el Panel Taller: la caja con los datos del
 * trabajo que se cargan en el mostrador y una columna con lo cobrado. La fecha
 * de entrega y la fase se guardan en las mismas claves que usa el taller, así
 * que sus columnas ya las muestran y no hace falta repetirlas.
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
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_columns' ), 20 );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_columns' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_column' ), 20, 2 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_column' ), 20, 2 );

		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ), 40, 2 );
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save_meta_box' ), 40, 2 );

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Si estamos en alguna pantalla de pedidos.
	 *
	 * @return bool
	 */
	public static function is_orders_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen ) {
			return false;
		}

		return in_array( $screen->id, array( 'edit-shop_order', 'shop_order', 'woocommerce_page_wc-orders' ), true );
	}

	/**
	 * Agrega la columna de cobros.
	 *
	 * @param array $columns Columnas existentes.
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
				$new['io_pos_payments'] = __( 'Cobrado', 'io-punto-venta' );
			}
		}

		if ( ! isset( $new['io_pos_payments'] ) ) {
			$new['io_pos_payments'] = __( 'Cobrado', 'io-punto-venta' );
		}

		return $new;
	}

	/**
	 * Dibuja la columna.
	 *
	 * @param string       $column La columna.
	 * @param int|WC_Order $order  Pedido o su ID.
	 */
	public function render_column( $column, $order ) {
		if ( 'io_pos_payments' !== $column ) {
			return;
		}

		$order = io_pos_get_order( $order );

		if ( ! $order ) {
			return;
		}

		echo wp_kses_post( self::get_payments_badge( $order ) );
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
	 * Registra la caja con los datos del trabajo.
	 *
	 * @param string $screen_id La pantalla.
	 * @param mixed  $order     El pedido.
	 */
	public function add_meta_box( $screen_id, $order = null ) {
		if ( ! in_array( $screen_id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true ) ) {
			return;
		}

		if ( ! IO_POS_Settings::is_enabled( 'job_enabled' ) ) {
			return;
		}

		add_meta_box(
			'io-pos-job',
			__( 'Trabajo de imprenta', 'io-punto-venta' ),
			array( $this, 'render_meta_box' ),
			$screen_id,
			'side',
			'default'
		);
	}

	/**
	 * Dibuja la caja del trabajo.
	 *
	 * @param mixed $post_or_order El pedido o su post.
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

		printf(
			'<p class="io-pos-hint">%s</p>',
			esc_html__( 'La fecha de entrega y la fase son las mismas que ve el Panel Taller.', 'io-punto-venta' )
		);

		echo '</div>';
	}

	/**
	 * Guarda la caja del trabajo.
	 *
	 * @param int   $order_id El ID del pedido.
	 * @param mixed $order    El pedido.
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

		$order->save();

		IO_POS_Job::sanitize_order_meta( $order );
	}

	/**
	 * Carga los estilos de la administración.
	 *
	 * @param string $hook La pantalla actual.
	 */
	public function enqueue_assets( $hook ) {
		if ( ! self::is_orders_screen() ) {
			return;
		}

		wp_enqueue_style( 'io-pos-admin', IO_POS_ASSETS_URL . 'css/admin.css', array(), IO_POS_VERSION );
	}
}
