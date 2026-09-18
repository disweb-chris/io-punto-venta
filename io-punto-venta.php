<?php
/**
 * Plugin Name: IO Punto de Venta para Imprenta
 * Plugin URI:  https://github.com/disweb-chris/io-punto-venta
 * Description: Extiende YITH Point of Sale for WooCommerce y lo adapta al flujo de trabajo de una imprenta: buscador de productos usable, fecha de entrega, datos del trabajo, estados de producción y gestión de señas/saldos.
 * Version:     1.0.0
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

define( 'IO_POS_VERSION', '1.0.0' );
define( 'IO_POS_FILE', __FILE__ );
define( 'IO_POS_DIR', plugin_dir_path( __FILE__ ) );
define( 'IO_POS_URL', plugin_dir_url( __FILE__ ) );
define( 'IO_POS_INCLUDES', IO_POS_DIR . 'includes/' );
define( 'IO_POS_ASSETS_URL', IO_POS_URL . 'assets/' );

/**
 * Declare compatibility with WooCommerce features (HPOS, cart/checkout blocks).
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
 * Show an admin notice when a requirement is missing.
 *
 * @param string $message The message to print.
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
 * Bootstrap the plugin once all the plugins are loaded.
 */
function io_pos_bootstrap() {
	if ( ! function_exists( 'WC' ) ) {
		io_pos_requirement_notice( __( '<strong>IO Punto de Venta para Imprenta</strong> necesita WooCommerce activo para funcionar.', 'io-punto-venta' ) );

		return;
	}

	if ( ! defined( 'YITH_POS' ) ) {
		io_pos_requirement_notice( __( '<strong>IO Punto de Venta para Imprenta</strong> necesita el plugin <em>YITH Point of Sale for WooCommerce</em> activo para funcionar.', 'io-punto-venta' ) );

		return;
	}

	require_once IO_POS_INCLUDES . 'functions-io-pos.php';
	require_once IO_POS_INCLUDES . 'class-io-pos-settings.php';
	require_once IO_POS_INCLUDES . 'class-io-pos-job.php';
	require_once IO_POS_INCLUDES . 'class-io-pos-plugin.php';

	io_pos();
}
add_action( 'plugins_loaded', 'io_pos_bootstrap', 20 );

/**
 * Load the plugin text domain.
 */
add_action(
	'init',
	function () {
		load_plugin_textdomain( 'io-punto-venta', false, dirname( plugin_basename( IO_POS_FILE ) ) . '/languages' );
	}
);
