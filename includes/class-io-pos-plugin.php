<?php
/**
 * Main plugin class.
 *
 * @package IO\POS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class IO_POS_Plugin
 */
class IO_POS_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var IO_POS_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Loaded modules, keyed by slug.
	 *
	 * @var array
	 */
	private $modules = array();

	/**
	 * Get the singleton instance.
	 *
	 * @return IO_POS_Plugin
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->load_modules();
	}

	/**
	 * Load every module of the plugin.
	 */
	private function load_modules() {
		$files = array(
			'search'     => 'modules/class-io-pos-search.php',
			'pos'        => 'modules/class-io-pos-register.php',
			'deposit'    => 'modules/class-io-pos-deposit.php',
			'display'    => 'modules/class-io-pos-order-display.php',
			'orders'     => 'admin/class-io-pos-admin-orders.php',
			'board'      => 'admin/class-io-pos-admin-board.php',
			'settings'   => 'admin/class-io-pos-admin-settings.php',
		);

		$classes = array(
			'search'   => 'IO_POS_Search',
			'pos'      => 'IO_POS_Register',
			'deposit'  => 'IO_POS_Deposit',
			'display'  => 'IO_POS_Order_Display',
			'orders'   => 'IO_POS_Admin_Orders',
			'board'    => 'IO_POS_Admin_Board',
			'settings' => 'IO_POS_Admin_Settings',
		);

		$admin_only = array( 'orders', 'board', 'settings' );

		foreach ( $files as $slug => $file ) {
			if ( in_array( $slug, $admin_only, true ) && ! is_admin() ) {
				continue;
			}

			$path = IO_POS_INCLUDES . $file;

			if ( ! file_exists( $path ) ) {
				continue;
			}

			require_once $path;

			$class = $classes[ $slug ];

			if ( class_exists( $class ) ) {
				$this->modules[ $slug ] = new $class();
			}
		}
	}

	/**
	 * Get a loaded module.
	 *
	 * @param string $slug Module slug.
	 *
	 * @return object|null
	 */
	public function module( $slug ) {
		return $this->modules[ $slug ] ?? null;
	}
}
