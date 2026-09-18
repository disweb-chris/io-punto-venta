<?php
/**
 * Banco de pruebas independiente de WordPress.
 *
 * Define lo mínimo de WordPress y WooCommerce que usan las clases del plugin y
 * monta un $wpdb sobre SQLite, para poder ejecutar de verdad las consultas del
 * buscador sin levantar un WordPress completo.
 *
 * Uso: php tests/run-tests.php
 *
 * @package IO\POS
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'IO_POS_VERSION', 'test' );
define( 'IO_POS_FILE', dirname( __DIR__ ) . '/io-punto-venta.php' );
define( 'IO_POS_DIR', dirname( __DIR__ ) . '/' );
define( 'IO_POS_URL', 'http://example.test/wp-content/plugins/io-punto-venta/' );
define( 'IO_POS_INCLUDES', IO_POS_DIR . 'includes/' );
define( 'IO_POS_ASSETS_URL', IO_POS_URL . 'assets/' );

$GLOBALS['io_pos_test_options'] = array();
$GLOBALS['io_pos_test_filters'] = array();

/* -------------------------------------------------------------------------
 * Funciones de WordPress
 * ---------------------------------------------------------------------- */

function __( $text, $domain = '' ) {
	return $text;
}

function _x( $text, $context = '', $domain = '' ) {
	return $text;
}

function esc_html__( $text, $domain = '' ) {
	return $text;
}

function apply_filters( $tag, $value ) {
	$args = array_slice( func_get_args(), 2 );

	foreach ( $GLOBALS['io_pos_test_filters'][ $tag ] ?? array() as $callback ) {
		$value = call_user_func_array( $callback, array_merge( array( $value ), $args ) );
	}

	return $value;
}

function add_filter( $tag, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['io_pos_test_filters'][ $tag ][] = $callback;

	return true;
}

function add_action( $tag, $callback, $priority = 10, $args = 1 ) {
	return add_filter( $tag, $callback, $priority, $args );
}

function remove_action( $tag, $callback, $priority = 10 ) {
	unset( $GLOBALS['io_pos_test_filters'][ $tag ] );

	return true;
}

function do_action( $tag ) {
	$args = array_slice( func_get_args(), 1 );

	foreach ( $GLOBALS['io_pos_test_filters'][ $tag ] ?? array() as $callback ) {
		call_user_func_array( $callback, $args );
	}
}

function get_option( $name, $default = false ) {
	return $GLOBALS['io_pos_test_options'][ $name ] ?? $default;
}

function update_option( $name, $value ) {
	$GLOBALS['io_pos_test_options'][ $name ] = $value;

	return true;
}

function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( $defaults, (array) $args );
}

function sanitize_key( $key ) {
	return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $key ) );
}

function sanitize_text_field( $value ) {
	return trim( preg_replace( '/[\r\n\t]+/', ' ', wp_strip_all_tags( (string) $value ) ) );
}

function sanitize_textarea_field( $value ) {
	return trim( wp_strip_all_tags( (string) $value ) );
}

function wp_strip_all_tags( $value ) {
	return strip_tags( (string) $value );
}

function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function absint( $value ) {
	return abs( (int) $value );
}

function wp_list_pluck( $list, $field ) {
	return array_map(
		function ( $item ) use ( $field ) {
			return is_array( $item ) ? ( $item[ $field ] ?? null ) : ( $item->$field ?? null );
		},
		$list
	);
}

function wp_checkdate( $month, $day, $year, $source ) {
	return checkdate( $month, $day, $year );
}

function wp_timezone() {
	return new DateTimeZone( 'UTC' );
}

function current_datetime() {
	return new DateTimeImmutable( 'now', wp_timezone() );
}

function wp_date( $format, $timestamp = null ) {
	return gmdate( $format, $timestamp ?? time() );
}

function wc_format_decimal( $value, $decimals = false ) {
	$value = (float) str_replace( ',', '.', (string) $value );

	return false === $decimals ? (string) $value : number_format( $value, (int) $decimals, '.', '' );
}

function wc_get_price_decimals() {
	return 2;
}

function yith_pos_get_barcode_meta() {
	return '_sku';
}

function current_user_can( $capability ) {
	$caps = $GLOBALS['io_pos_test_caps'] ?? array();

	return ! empty( $caps[ $capability ] );
}

function get_current_user_id() {
	return 1;
}

function current_time( $type = 'mysql' ) {
	return 'timestamp' === $type ? time() : gmdate( 'Y-m-d H:i:s' );
}

function wp_generate_uuid4() {
	return sprintf( '%04x%04x-%04x', wp_rand(), wp_rand(), wp_rand() );
}

function wp_rand( $min = 0, $max = 65535 ) {
	return random_int( $min, $max );
}

function wc_price( $amount, $args = array() ) {
	return '$' . number_format( (float) $amount, 2, ',', '.' );
}

function wc_get_order_statuses() {
	return array(
		'wc-pending'    => 'Pendiente de pago',
		'wc-processing' => 'Procesando',
		'wc-on-hold'    => 'En espera',
		'wc-completed'  => 'Completado',
		'wc-cancelled'  => 'Cancelado',
	);
}

function io_pos_format_price( $amount, $currency = '' ) {
	return wc_price( $amount );
}

class WP_Error {

	private $code;
	private $message;
	private $data;

	public function __construct( $code = '', $message = '', $data = array() ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}

	public function add_data( $data ) {
		$this->data = $data;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

/* -------------------------------------------------------------------------
 * $wpdb sobre SQLite
 * ---------------------------------------------------------------------- */

class IO_POS_Test_WPDB {

	public $posts    = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $queries  = array();

	/**
	 * @var PDO
	 */
	private $pdo;

	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );

		$this->pdo->exec(
			'CREATE TABLE wp_posts (
				ID INTEGER PRIMARY KEY,
				post_title TEXT,
				post_excerpt TEXT,
				post_type TEXT,
				post_status TEXT,
				post_parent INTEGER DEFAULT 0
			)'
		);

		$this->pdo->exec(
			'CREATE TABLE wp_postmeta (
				meta_id INTEGER PRIMARY KEY,
				post_id INTEGER,
				meta_key TEXT,
				meta_value TEXT
			)'
		);
	}

	public function seed_product( $id, $title, $excerpt, $sku, $type = 'product', $parent = 0, $status = 'publish' ) {
		$statement = $this->pdo->prepare( 'INSERT INTO wp_posts (ID, post_title, post_excerpt, post_type, post_status, post_parent) VALUES (?, ?, ?, ?, ?, ?)' );
		$statement->execute( array( $id, $title, $excerpt, $type, $status, $parent ) );

		if ( null !== $sku ) {
			$meta = $this->pdo->prepare( 'INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (?, ?, ?)' );
			$meta->execute( array( $id, '_sku', $sku ) );
		}
	}

	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		$index = 0;

		return preg_replace_callback(
			'/%[sdf]/',
			function ( $matches ) use ( &$index, $args ) {
				$value = $args[ $index ] ?? '';
				$index++;

				if ( '%d' === $matches[0] ) {
					return (string) (int) $value;
				}

				if ( '%f' === $matches[0] ) {
					return (string) (float) $value;
				}

				return "'" . str_replace( "'", "''", (string) $value ) . "'";
			},
			$query
		);
	}

	public function get_post_type( $id ) {
		$statement = $this->pdo->prepare( 'SELECT post_type FROM wp_posts WHERE ID = ?' );
		$statement->execute( array( $id ) );

		return (string) $statement->fetchColumn();
	}

	public function get_col( $query ) {
		$this->queries[] = $query;

		$statement = $this->pdo->query( $query );

		return $statement ? $statement->fetchAll( PDO::FETCH_COLUMN, 0 ) : array();
	}
}

function get_post_type( $id ) {
	global $wpdb;

	return $wpdb->get_post_type( $id );
}

/* -------------------------------------------------------------------------
 * Dobles de WooCommerce
 * ---------------------------------------------------------------------- */

class WP_REST_Request implements ArrayAccess {

	private $params;

	public function __construct( array $params = array() ) {
		$this->params = $params;
	}

	#[\ReturnTypeWillChange]
	public function offsetExists( $offset ) {
		return isset( $this->params[ $offset ] );
	}

	#[\ReturnTypeWillChange]
	public function offsetGet( $offset ) {
		return $this->params[ $offset ] ?? null;
	}

	#[\ReturnTypeWillChange]
	public function offsetSet( $offset, $value ) {
		$this->params[ $offset ] = $value;
	}

	#[\ReturnTypeWillChange]
	public function offsetUnset( $offset ) {
		unset( $this->params[ $offset ] );
	}
}

class WC_Order {

	private $meta      = array();
	private $saves     = 0;
	private $total     = 0.0;
	private $status    = 'pending';
	private $notes     = array();
	private $date_paid = null;

	public function __construct( array $meta = array(), $total = 0.0 ) {
		$this->meta  = $meta;
		$this->total = (float) $total;
	}

	public function get_total() {
		return $this->total;
	}

	public function set_total( $total ) {
		$this->total = (float) $total;
	}

	public function get_currency() {
		return 'ARS';
	}

	public function get_status() {
		return $this->status;
	}

	public function set_status( $status ) {
		$this->status = $status;
	}

	public function add_order_note( $note ) {
		$this->notes[] = $note;

		return count( $this->notes );
	}

	public function get_notes() {
		return $this->notes;
	}

	public function get_date_paid( $context = 'view' ) {
		return $this->date_paid;
	}

	public function set_date_paid( $date ) {
		$this->date_paid = $date;
	}

	public function get_meta( $key, $single = true ) {
		return $this->meta[ $key ] ?? '';
	}

	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}

	public function delete_meta_data( $key ) {
		unset( $this->meta[ $key ] );
	}

	public function get_all_meta() {
		return $this->meta;
	}

	public function save() {
		$this->saves++;

		return true;
	}

	public function get_save_count() {
		return $this->saves;
	}
}

require_once IO_POS_INCLUDES . 'functions-io-pos.php';
require_once IO_POS_INCLUDES . 'class-io-pos-settings.php';
require_once IO_POS_INCLUDES . 'class-io-pos-job.php';
require_once IO_POS_INCLUDES . 'class-io-pos-payments.php';
require_once IO_POS_INCLUDES . 'class-io-pos-order-builder.php';
require_once IO_POS_INCLUDES . 'modules/class-io-pos-search.php';
