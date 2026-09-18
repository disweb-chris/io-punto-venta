<?php
/**
 * Plugin Name: IO Punto de Venta para Imprenta
 * Plugin URI:  https://github.com/disweb-chris/io-punto-venta
 * Description: Punto de venta propio para WooCommerce pensado para una imprenta: mostrador táctil, cobro total o con seña, fecha de entrega, datos del trabajo, estados de producción e historial con reimpresión.
 * Version:     2.4.0
 * Author:      Disweb
 * Text Domain: io-punto-venta
 * Domain Path: /languages
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 10.0
 *
 * @package IO\POS
 */

defined( 'ABSPATH' ) || exit;

define( 'IO_POS_VERSION', '2.4.0' );
define( 'IO_POS_FILE', __FILE__ );
define( 'IO_POS_DIR', plugin_dir_path( __FILE__ ) );
define( 'IO_POS_URL', plugin_dir_url( __FILE__ ) );
define( 'IO_POS_INCLUDES', IO_POS_DIR . 'includes/' );
define( 'IO_POS_ASSETS_URL', IO_POS_URL . 'assets/' );

/**
 * Declara compatibilidad con las funciones de WooCommerce (HPOS).
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', IO_POS_FILE, true );
		}
	}
);

/**
 * Carga las clases del plugin.
 */
function io_pos_load_files() {
	$files = array(
		'functions-io-pos.php',
		'class-io-pos-settings.php',
		'class-io-pos-install.php',
		'class-io-pos-job.php',
		'class-io-pos-delivery.php',
		'class-io-pos-payments.php',
		'class-io-pos-order-builder.php',
		'class-io-pos-terminal.php',
		'rest/class-io-pos-rest-api.php',
		'class-io-pos-plugin.php',
	);

	foreach ( $files as $file ) {
		require_once IO_POS_INCLUDES . $file;
	}
}

/**
 * Muestra un aviso cuando falta algo para que el plugin funcione.
 *
 * @param string $message El aviso.
 */
function io_pos_requirement_notice( $message ) {
	add_action(
		'admin_notices',
		function () use ( $message ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', wp_kses_post( $message ) );
		}
	);
}

/**
 * Arranca el plugin.
 */
function io_pos_bootstrap() {
	if ( ! function_exists( 'WC' ) ) {
		io_pos_requirement_notice( __( '<strong>IO Punto de Venta para Imprenta</strong> necesita WooCommerce activo para funcionar.', 'io-punto-venta' ) );

		return;
	}

	io_pos_load_files();
	io_pos();

	// En admin_init, no antes: crear la página del mostrador necesita que los
	// tipos de contenido de WordPress ya estén registrados.
	add_action( 'admin_init', array( 'IO_POS_Install', 'maybe_upgrade' ) );
}
add_action( 'plugins_loaded', 'io_pos_bootstrap', 20 );

/**
 * Rutina de activación.
 */
function io_pos_activate() {
	if ( ! function_exists( 'WC' ) ) {
		return;
	}

	io_pos_load_files();
	IO_POS_Install::activate();
}
register_activation_hook( __FILE__, 'io_pos_activate' );

/**
 * Carga las traducciones.
 */
add_action(
	'init',
	function () {
		load_plugin_textdomain( 'io-punto-venta', false, dirname( plugin_basename( IO_POS_FILE ) ) . '/languages' );
	}
);
