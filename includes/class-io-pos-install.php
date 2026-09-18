<?php
/**
 * Instalación: permisos, rol de cajero y página del mostrador.
 *
 * @package IO\POS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class IO_POS_Install
 */
class IO_POS_Install {

	const ROLE = 'io_pos_cashier';

	/**
	 * Permisos que define el plugin.
	 *
	 * @return array<string,string> Permiso => descripción.
	 */
	public static function get_capabilities() {
		return array(
			'io_pos_use'             => __( 'Usar el mostrador', 'io-punto-venta' ),
			'io_pos_view_history'    => __( 'Ver el historial de ventas', 'io-punto-venta' ),
			'io_pos_manage_customers' => __( 'Crear y editar clientes', 'io-punto-venta' ),
			'io_pos_custom_item'     => __( 'Agregar trabajos a medida', 'io-punto-venta' ),
			'io_pos_edit_price'      => __( 'Cambiar el precio de una línea', 'io-punto-venta' ),
			'io_pos_discount'        => __( 'Aplicar descuentos', 'io-punto-venta' ),
			'io_pos_collect_balance' => __( 'Cobrar saldos pendientes', 'io-punto-venta' ),
			'io_pos_partial_payment' => __( 'Cobrar una seña', 'io-punto-venta' ),
		);
	}

	/**
	 * Permisos que recibe el rol de cajero.
	 *
	 * @return string[]
	 */
	public static function get_cashier_capabilities() {
		return array(
			'read',
			'io_pos_use',
			'io_pos_view_history',
			'io_pos_manage_customers',
			'io_pos_custom_item',
			'io_pos_partial_payment',
			'io_pos_collect_balance',
		);
	}

	/**
	 * Rutina de activación.
	 */
	public static function activate() {
		self::install_capabilities();
		self::create_terminal_page();

		update_option( 'io_pos_version', IO_POS_VERSION );
	}

	/**
	 * Crea el rol y reparte los permisos.
	 */
	public static function install_capabilities() {
		$capabilities = array_keys( self::get_capabilities() );

		add_role(
			self::ROLE,
			__( 'Cajero del mostrador', 'io-punto-venta' ),
			array_fill_keys( self::get_cashier_capabilities(), true )
		);

		$role = get_role( self::ROLE );

		if ( $role ) {
			foreach ( self::get_cashier_capabilities() as $capability ) {
				$role->add_cap( $capability );
			}
		}

		foreach ( array( 'administrator', 'shop_manager' ) as $role_name ) {
			$role = get_role( $role_name );

			if ( ! $role ) {
				continue;
			}

			foreach ( $capabilities as $capability ) {
				$role->add_cap( $capability );
			}
		}
	}

	/**
	 * Crea la página del mostrador si todavía no existe.
	 *
	 * @return int El ID de la página.
	 */
	public static function create_terminal_page() {
		$page_id = absint( IO_POS_Settings::get( 'terminal_page_id' ) );

		if ( $page_id && 'page' === get_post_type( $page_id ) && 'trash' !== get_post_status( $page_id ) ) {
			return $page_id;
		}

		$existing = get_page_by_path( 'mostrador' );

		if ( $existing ) {
			$page_id = $existing->ID;
		} else {
			$page_id = wp_insert_post(
				array(
					'post_title'     => __( 'Mostrador', 'io-punto-venta' ),
					'post_name'      => 'mostrador',
					'post_content'   => '',
					'post_status'    => 'publish',
					'post_type'      => 'page',
					'comment_status' => 'closed',
					'ping_status'    => 'closed',
				)
			);
		}

		if ( $page_id && ! is_wp_error( $page_id ) ) {
			IO_POS_Settings::update( array( 'terminal_page_id' => $page_id ) );

			return $page_id;
		}

		return 0;
	}

	/**
	 * Comprueba si hay que correr la instalación tras una actualización.
	 */
	public static function maybe_upgrade() {
		$installed = (string) get_option( 'io_pos_version' );

		if ( $installed === IO_POS_VERSION ) {
			return;
		}

		self::install_capabilities();
		self::create_terminal_page();
		self::migrate( $installed );

		update_option( 'io_pos_version', IO_POS_VERSION );
	}

	/**
	 * Ajusta los valores guardados cuando cambian los criterios.
	 *
	 * Solo toca instalaciones que ya existían: una nueva arranca con los
	 * valores por defecto.
	 *
	 * @param string $installed Versión que estaba instalada.
	 */
	public static function migrate( $installed ) {
		if ( ! $installed ) {
			return;
		}

		// 2.4.0: el campo del archivo pasó a guardarse en la clave que leen el
		// plugin de subida de archivos y el Panel Taller. Los ajustes que ya
		// estaban guardados seguían con la clave vieja, así que el enlace no se
		// veía en ningún lado más que en la caja del pedido.
		if ( version_compare( $installed, '2.4.0', '<' ) ) {
			$fields  = (string) IO_POS_Settings::get( 'job_custom_fields' );
			$updated = preg_replace( '/^archivo\|/m', 'archivo=_io_drive_link|', $fields );

			if ( $updated && $updated !== $fields ) {
				IO_POS_Settings::update( array( 'job_custom_fields' => $updated ) );
			}
		}

		// 2.3.0: cerrar la venta sin abrir la impresión, y dejar que
		// WooCommerce mande sus correos como en una compra por la web.
		if ( version_compare( $installed, '2.3.0', '<' ) ) {
			IO_POS_Settings::update(
				array(
					'receipt_auto_print' => 'no',
					'notify_emails'      => 'yes',
				)
			);
		}
	}
}
