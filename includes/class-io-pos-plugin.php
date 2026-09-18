<?php
/**
 * Clase principal: arma las piezas del plugin.
 *
 * @package IO\POS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class IO_POS_Plugin
 */
class IO_POS_Plugin {

	/**
	 * Instancia única.
	 *
	 * @var IO_POS_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Piezas cargadas.
	 *
	 * @var array
	 */
	private $modules = array();

	/**
	 * Devuelve la instancia única.
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
	 * Carga cada pieza del plugin.
	 */
	private function load_modules() {
		$modules = array(
			'search'   => array(
				'file'  => 'modules/class-io-pos-search.php',
				'class' => 'IO_POS_Search',
			),
			'terminal' => array(
				'class' => 'IO_POS_Terminal',
			),
			'rest'     => array(
				'class' => 'IO_POS_REST_API',
			),
			'display'  => array(
				'file'  => 'modules/class-io-pos-order-display.php',
				'class' => 'IO_POS_Order_Display',
			),
			'emails'   => array(
				'file'  => 'modules/class-io-pos-emails.php',
				'class' => 'IO_POS_Emails',
			),
			'yith'     => array(
				'file'      => 'modules/class-io-pos-yith-bridge.php',
				'class'     => 'IO_POS_Yith_Bridge',
				'condition' => 'yith',
			),
			'orders'   => array(
				'file'      => 'admin/class-io-pos-admin-orders.php',
				'class'     => 'IO_POS_Admin_Orders',
				'condition' => 'admin',
			),
			'board'    => array(
				'file'      => 'admin/class-io-pos-admin-board.php',
				'class'     => 'IO_POS_Admin_Board',
				'condition' => 'admin',
			),
			'settings' => array(
				'file'      => 'admin/class-io-pos-admin-settings.php',
				'class'     => 'IO_POS_Admin_Settings',
				'condition' => 'admin',
			),
		);

		foreach ( $modules as $slug => $module ) {
			if ( ! $this->passes_condition( $module['condition'] ?? '' ) ) {
				continue;
			}

			if ( ! empty( $module['file'] ) ) {
				$path = IO_POS_INCLUDES . $module['file'];

				if ( ! file_exists( $path ) ) {
					continue;
				}

				require_once $path;
			}

			if ( class_exists( $module['class'] ) ) {
				$this->modules[ $slug ] = new $module['class']();
			}
		}
	}

	/**
	 * Comprueba la condición de carga de una pieza.
	 *
	 * @param string $condition La condición.
	 *
	 * @return bool
	 */
	private function passes_condition( $condition ) {
		if ( 'admin' === $condition ) {
			return is_admin();
		}

		if ( 'yith' === $condition ) {
			return defined( 'YITH_POS' );
		}

		return true;
	}

	/**
	 * Devuelve una pieza cargada.
	 *
	 * @param string $slug Nombre de la pieza.
	 *
	 * @return object|null
	 */
	public function module( $slug ) {
		return $this->modules[ $slug ] ?? null;
	}
}
