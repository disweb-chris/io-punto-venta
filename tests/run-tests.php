<?php
/**
 * Pruebas del plugin.
 *
 * Uso: php tests/run-tests.php
 *
 * @package IO\POS
 */

require_once __DIR__ . '/bootstrap.php';

$results = array(
	'passed' => 0,
	'failed' => 0,
);

/**
 * Comprueba una condición.
 *
 * @param string $name      Nombre de la prueba.
 * @param mixed  $expected  Valor esperado.
 * @param mixed  $actual    Valor obtenido.
 */
function io_pos_assert( $name, $expected, $actual ) {
	global $results;

	if ( $expected === $actual ) {
		$results['passed']++;

		echo "  ok   {$name}\n";

		return;
	}

	$results['failed']++;

	echo "  FAIL {$name}\n";
	echo '       esperado: ' . str_replace( "\n", ' ', var_export( $expected, true ) ) . "\n";
	echo '       obtenido: ' . str_replace( "\n", ' ', var_export( $actual, true ) ) . "\n";
}

function io_pos_section( $title ) {
	echo "\n{$title}\n";
}

/* ---------------------------------------------------------------------- */
io_pos_section( 'Fechas' );

io_pos_assert( 'formato ISO', '2026-09-30', io_pos_normalize_date( '2026-09-30' ) );
io_pos_assert( 'formato local d/m/Y', '2026-09-30', io_pos_normalize_date( '30/09/2026' ) );
io_pos_assert( 'formato local d-m-y', '2026-09-30', io_pos_normalize_date( '30-9-26' ) );
io_pos_assert( 'fecha inexistente', '', io_pos_normalize_date( '2026-02-31' ) );
io_pos_assert( 'valor vacío', '', io_pos_normalize_date( '' ) );
io_pos_assert( 'texto suelto', '', io_pos_normalize_date( 'mañana temprano' ) );

$today    = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d' );
$tomorrow = ( new DateTimeImmutable( '+1 day', new DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d' );
$past     = ( new DateTimeImmutable( '-3 days', new DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d' );
$far      = ( new DateTimeImmutable( '+10 days', new DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d' );

io_pos_assert( 'días hasta hoy', 0, io_pos_days_until( $today ) );
io_pos_assert( 'días hasta mañana', 1, io_pos_days_until( $tomorrow ) );
io_pos_assert( 'días vencidos', -3, io_pos_days_until( $past ) );
io_pos_assert( 'estado vencido', 'overdue', io_pos_delivery_state( $past ) );
io_pos_assert( 'estado hoy', 'today', io_pos_delivery_state( $today ) );
io_pos_assert( 'estado próximo', 'soon', io_pos_delivery_state( $tomorrow ) );
io_pos_assert( 'estado programado', 'scheduled', io_pos_delivery_state( $far ) );
io_pos_assert( 'estado sin fecha', 'none', io_pos_delivery_state( '' ) );

/* ---------------------------------------------------------------------- */
io_pos_section( 'Ajustes' );

io_pos_assert(
	'líneas sueltas',
	array( 'Mañana (9 a 13)', 'Tarde (14 a 18)' ),
	IO_POS_Settings::get_lines( 'job_time_slots' )
);

io_pos_assert(
	'pares clave|etiqueta',
	array(
		'diseno'      => 'Diseño',
		'produccion'  => 'Producción',
		'terminacion' => 'Terminación',
		'taller'      => 'Taller',
		'entregado'   => 'Entregado',
	),
	IO_POS_Settings::get_pairs( 'production_statuses' )
);

$sanitized = IO_POS_Settings::sanitize(
	array(
		'search_enabled'     => 'yes',
		'search_max_results' => '5000',
		'search_min_chars'   => '0',
		'job_default_days'   => '4',
		'production_default' => 'produccion',
	)
);

io_pos_assert( 'tope de resultados', 100, $sanitized['search_max_results'] );
io_pos_assert( 'mínimo de letras', 1, $sanitized['search_min_chars'] );
io_pos_assert( 'casilla marcada', 'yes', $sanitized['search_enabled'] );
io_pos_assert( 'casilla ausente pasa a no', 'no', $sanitized['job_delivery_required'] );
io_pos_assert( 'número dentro del rango', 4, $sanitized['job_default_days'] );
io_pos_assert( 'texto libre', 'produccion', $sanitized['production_default'] );

/* ---------------------------------------------------------------------- */
io_pos_section( 'Campos del trabajo' );

update_option(
	IO_POS_Settings::OPTION,
	array(
		'job_custom_fields' => "material|Material|text|||\n"
			. "medidas|Medidas|text|||ticket\n"
			. "terminacion|Terminación|select|Laminado mate,Troquelado|obligatorio|ticket\n"
			. "cantidad|Cantidad de pliegos|number|||\n"
			. "|sin clave|text|||\n"
			. "tipo raro|Tipo inventado|inexistente|||",
	)
);

$reset = new ReflectionProperty( 'IO_POS_Settings', 'settings' );
$reset->setAccessible( true );
$reset->setValue( null, null );

$fields_reset = new ReflectionProperty( 'IO_POS_Job', 'fields' );
$fields_reset->setAccessible( true );
$fields_reset->setValue( null, null );

$fields = IO_POS_Job::get_custom_fields();

io_pos_assert( 'campos válidos', array( 'material', 'medidas', 'terminacion', 'cantidad', 'tiporaro' ), array_keys( $fields ) );
io_pos_assert( 'marca ticket', true, $fields['medidas']['receipt'] );
io_pos_assert( 'marca obligatorio', true, $fields['terminacion']['required'] );
io_pos_assert( 'opciones del select', array( 'Laminado mate', 'Troquelado' ), $fields['terminacion']['options'] );
io_pos_assert( 'tipo número', 'number', $fields['cantidad']['type'] );
io_pos_assert( 'tipo desconocido pasa a texto', 'text', $fields['tiporaro']['type'] );
io_pos_assert( 'clave del meta', '_io_pos_field_material', $fields['material']['meta_key'] );

$schema = IO_POS_Job::get_schema();

io_pos_assert( 'la fecha de entrega va primero', 'delivery_date', array_key_first( $schema ) );
io_pos_assert( 'las notas van al final', 'notes', array_key_last( $schema ) );

/* ---------------------------------------------------------------------- */
io_pos_section( 'Normalización del pedido' );

$order = new WC_Order(
	array(
		IO_POS_Job::META_DELIVERY_DATE => '30/09/2026',
		IO_POS_Job::META_DELIVERY_TIME => 'Un horario inventado',
		'_io_pos_field_material'       => '  Cartulina 300g  ',
		'_io_pos_field_terminacion'    => 'Laminado mate',
		'_io_pos_field_cantidad'       => '12,5',
		IO_POS_Job::META_STATUS        => 'inventado',
		'_io_pos_balance_due'          => '1500',
	)
);

IO_POS_Job::sanitize_order_meta( $order );
$meta = $order->get_all_meta();

io_pos_assert( 'fecha normalizada', '2026-09-30', $meta[ IO_POS_Job::META_DELIVERY_DATE ] );
io_pos_assert( 'opción inválida descartada', false, isset( $meta[ IO_POS_Job::META_DELIVERY_TIME ] ) );
io_pos_assert( 'texto recortado', 'Cartulina 300g', $meta['_io_pos_field_material'] );
io_pos_assert( 'opción válida conservada', 'Laminado mate', $meta['_io_pos_field_terminacion'] );
io_pos_assert( 'número normalizado', '12.5', $meta['_io_pos_field_cantidad'] );
io_pos_assert( 'fase inválida pasa a la inicial', 'diseno', $meta[ IO_POS_Job::META_STATUS ] );
io_pos_assert( 'no toca lo cobrado, que es del pedido', '1500', $meta['_io_pos_balance_due'] );

$empty = new WC_Order();
IO_POS_Job::sanitize_order_meta( $empty );

io_pos_assert( 'un pedido sin trabajo no recibe estado', array(), $empty->get_all_meta() );

/* ---------------------------------------------------------------------- */
io_pos_section( 'Buscador' );

global $wpdb;
$wpdb = new IO_POS_Test_WPDB();

$wpdb->seed_product( 1, 'Tarjetas personales 9x5 cartulina', 'Impresión full color', 'TAR-9X5' );
$wpdb->seed_product( 2, 'Tarjetas personales 9x5 plastificadas', 'Laminado brillante', 'TAR-9X5-PLAS' );
$wpdb->seed_product( 3, 'Folletos A5 doble faz', 'Papel ilustración', 'FOL-A5' );
$wpdb->seed_product( 4, 'Banner lona 1x2', 'Impresión en lona', 'BAN-1X2' );
$wpdb->seed_product( 5, 'Sellos automáticos', 'Sellos de goma', null );
$wpdb->seed_product( 6, 'Remeras estampadas', 'Vinilo textil', 'REM-EST' );
$wpdb->seed_product( 7, 'Remeras estampadas - Talle L', '', 'REM-EST-L', 'product_variation', 6 );
$wpdb->seed_product( 8, 'Borrador de tarjetas', 'No publicado', 'TAR-BORR', 'product', 0, 'draft' );

update_option(
	IO_POS_Settings::OPTION,
	array(
		'search_max_results'         => 30,
		'search_include_sku'         => 'yes',
		'search_include_variations'  => 'yes',
		'search_include_description' => 'yes',
		'search_force_selectable'    => 'yes',
	)
);
$reset->setValue( null, null );

$search  = new IO_POS_Search();
$request = new WP_REST_Request(
	array(
		'yith_pos_request' => 'search-products',
		'yith_pos_scan'    => 'no',
	)
);

/**
 * Ejecuta una búsqueda y devuelve los IDs encontrados.
 *
 * @param IO_POS_Search        $search  El módulo.
 * @param WP_REST_Request      $request La petición.
 * @param string               $term    Lo que se escribe en el buscador.
 * @return array
 */
function io_pos_search( $search, $request, $term ) {
	$args = $search->filter_product_query( array( 's' => $term ), $request );

	return array_map( 'intval', $args['post__in'] ?? array() );
}

io_pos_assert( 'una palabra', array( 1, 2 ), io_pos_search( $search, $request, 'tarjetas' ) );
io_pos_assert( 'dos palabras sueltas en el título', array( 2 ), io_pos_search( $search, $request, 'tarjetas plastificadas' ) );
io_pos_assert( 'palabras en cualquier orden', array( 1, 2 ), io_pos_search( $search, $request, '9x5 personales' ) );
io_pos_assert( 'búsqueda por SKU parcial', array( 3 ), io_pos_search( $search, $request, 'FOL-A5' ) );
io_pos_assert( 'búsqueda por descripción corta', array( 4 ), io_pos_search( $search, $request, 'lona' ) );
io_pos_assert( 'producto sin SKU', array( 5 ), io_pos_search( $search, $request, 'sellos' ) );
io_pos_assert( 'SKU de variación', array( 7 ), io_pos_search( $search, $request, 'REM-EST-L' ) );
io_pos_assert( 'no incluye borradores', array( 0 ), io_pos_search( $search, $request, 'borrador' ) );
io_pos_assert( 'sin resultados no devuelve todo', array( 0 ), io_pos_search( $search, $request, 'zzzz' ) );
io_pos_assert( 'frase entre comillas', array( 1, 2 ), io_pos_search( $search, $request, '"tarjetas personales"' ) );

$args = $search->filter_product_query( array( 's' => 'tarjetas' ), $request );
io_pos_assert( 'se quita la búsqueda original', false, isset( $args['s'] ) );
io_pos_assert( 'se respeta el orden encontrado', 'post__in', $args['orderby'] );

update_option( IO_POS_Settings::OPTION, array( 'search_max_results' => 1 ) );
$reset->setValue( null, null );
io_pos_assert( 'se respeta el límite', 1, count( io_pos_search( $search, $request, 'tarjetas' ) ) );

update_option( IO_POS_Settings::OPTION, array( 'search_include_sku' => 'no' ) );
$reset->setValue( null, null );
io_pos_assert( 'sin búsqueda por SKU', array( 0 ), io_pos_search( $search, $request, 'FOL-A5' ) );

update_option( IO_POS_Settings::OPTION, array() );
$reset->setValue( null, null );

$scan_request = new WP_REST_Request(
	array(
		'yith_pos_request' => 'search-products',
		'yith_pos_scan'    => 'yes',
	)
);
$scan_args    = $search->filter_product_query( array( 's' => 'TAR-9X5' ), $scan_request );
io_pos_assert( 'no toca el escáner de códigos', 'TAR-9X5', $scan_args['s'] ?? '' );

$list_request = new WP_REST_Request( array( 'yith_pos_request' => 'get-products' ) );
$list_args    = $search->filter_product_query(
	array(
		'meta_query' => array(
			array(
				'key'     => '_price',
				'value'   => '',
				'compare' => '!=',
			),
			array(
				'key'     => '_stock_status',
				'value'   => array( 'instock' ),
				'compare' => 'IN',
			),
		),
	),
	$list_request
);

io_pos_assert( 'quita el filtro de productos con precio', 1, count( $list_args['meta_query'] ) );
io_pos_assert( 'conserva el resto de los filtros', '_stock_status', $list_args['meta_query'][0]['key'] );

$other_args = $search->filter_product_query( array( 's' => 'tarjetas' ), new WP_REST_Request( array() ) );
io_pos_assert( 'no toca las consultas ajenas al POS', 'tarjetas', $other_args['s'] ?? '' );

/* ---------------------------------------------------------------------- */
io_pos_section( 'Cobros' );

update_option(
	IO_POS_Settings::OPTION,
	array(
		'payment_methods'         => "efectivo|Efectivo\ntransferencia|Transferencia",
		'payment_cash_method'     => 'efectivo',
		'payment_allow_partial'   => 'yes',
		'payment_status_paid'     => 'completed',
		'payment_status_partial'  => 'processing',
		'payment_status_unpaid'   => 'pending',
		'production_enabled'      => 'yes',
		'production_order_status' => 'processing',
	)
);
$reset->setValue( null, null );

$GLOBALS['io_pos_test_caps'] = array( 'io_pos_partial_payment' => true );

$sale = new WC_Order( array( IO_POS_Job::META_DELIVERY_DATE => '2026-10-15' ), 10000 );

io_pos_assert( 'sin cobros, saldo completo', 10000.0, IO_POS_Payments::get_balance( $sale ) );
io_pos_assert( 'sin cobros, nada cobrado', 0.0, IO_POS_Payments::get_paid_total( $sale ) );
io_pos_assert( 'estado sin cobrar', 'pending', IO_POS_Payments::get_target_status( $sale ) );

IO_POS_Payments::add_payment( $sale, 'efectivo', 3000 );

io_pos_assert( 'seña registrada', 3000.0, IO_POS_Payments::get_paid_total( $sale ) );
io_pos_assert( 'saldo tras la seña', 7000.0, IO_POS_Payments::get_balance( $sale ) );
io_pos_assert( 'estado con saldo', 'processing', IO_POS_Payments::get_target_status( $sale ) );
io_pos_assert( 'se anota el cobro', 1, count( $sale->get_notes() ) );
io_pos_assert( 'meta de lo cobrado', '3000.00', $sale->get_meta( IO_POS_Payments::META_PAID ) );
io_pos_assert( 'meta del saldo', '7000.00', $sale->get_meta( IO_POS_Payments::META_BALANCE ) );
io_pos_assert( 'sin fecha de pago mientras haya saldo', null, $sale->get_date_paid() );

IO_POS_Payments::add_payment( $sale, 'transferencia', 7000 );

io_pos_assert( 'saldo saldado', 0.0, IO_POS_Payments::get_balance( $sale ) );
io_pos_assert( 'estado con trabajo y todo cobrado', 'processing', IO_POS_Payments::get_target_status( $sale ) );
io_pos_assert( 'fecha de pago al saldar', true, null !== $sale->get_date_paid() );

io_pos_assert(
	'totales por método',
	array(
		'efectivo'      => 3000.0,
		'transferencia' => 7000.0,
	),
	IO_POS_Payments::get_totals_by_method( $sale )
);

$plain = new WC_Order( array(), 500 );
IO_POS_Payments::add_payment( $plain, 'efectivo', 500 );

io_pos_assert( 'estado sin trabajo y todo cobrado', 'completed', IO_POS_Payments::get_target_status( $plain ) );

$bad_method = IO_POS_Payments::add_payment( new WC_Order( array(), 100 ), 'bitcoin', 50 );
io_pos_assert( 'método inexistente', 'io_pos_invalid_method', is_wp_error( $bad_method ) ? $bad_method->get_error_code() : '' );

$bad_amount = IO_POS_Payments::add_payment( new WC_Order( array(), 100 ), 'efectivo', 0 );
io_pos_assert( 'importe cero', 'io_pos_invalid_amount', is_wp_error( $bad_amount ) ? $bad_amount->get_error_code() : '' );

update_option( IO_POS_Settings::OPTION, array( 'payment_status_partial' => 'inventado' ) );
$reset->setValue( null, null );

$fallback = new WC_Order( array(), 1000 );
IO_POS_Payments::add_payment( $fallback, 'efectivo', 400, array( 'silent' => true ) );

io_pos_assert( 'estado inválido cae en procesando', 'processing', IO_POS_Payments::get_target_status( $fallback ) );

/* ---------------------------------------------------------------------- */
io_pos_section( 'Historial de pagos compartido' );

update_option(
	IO_POS_Settings::OPTION,
	array(
		'payment_methods'       => "efectivo|Efectivo\ntransferencia|Transferencia\nmercadopago|Mercado Pago",
		'payment_cash_method'   => 'efectivo',
		'payment_allow_partial' => 'yes',
	)
);
$reset->setValue( null, null );

$shared = new WC_Order( array(), 10000 );

IO_POS_Payments::add_payment( $shared, 'transferencia', 4000, array( 'silent' => true ) );
$history = $shared->get_meta( IO_POS_Payments::META_HISTORY );

io_pos_assert( 'se guarda en la clave del metabox de pagos', true, is_array( $history ) && 1 === count( $history ) );
io_pos_assert( 'usa las claves del metabox', array( 'tipo', 'metodo', 'monto', 'fecha', 'user' ), array_keys( $history[0] ) );
io_pos_assert( 'un cobro parcial es una seña', 'seña', $history[0]['tipo'] );
io_pos_assert( 'guarda el método tal cual', 'transferencia', $history[0]['metodo'] );
io_pos_assert( 'guarda el monto como número', 4000.0, $history[0]['monto'] );

IO_POS_Payments::add_payment( $shared, 'efectivo', 6000, array( 'silent' => true ) );
$history = $shared->get_meta( IO_POS_Payments::META_HISTORY );

io_pos_assert( 'el segundo cobro es el saldo', 'saldo', $history[1]['tipo'] );
io_pos_assert( 'no queda saldo', 0.0, IO_POS_Payments::get_balance( $shared ) );

$full = new WC_Order( array(), 2500 );
IO_POS_Payments::add_payment( $full, 'efectivo', 2500, array( 'silent' => true ) );
$history = $full->get_meta( IO_POS_Payments::META_HISTORY );

io_pos_assert( 'cobrar todo de una es un pago', 'pago', $history[0]['tipo'] );

$legacy = new WC_Order( array( '_io_pagos_historial' => array( array( 'tipo' => 'seña', 'metodo' => 'efectivo', 'monto' => 3000 ) ) ), 5000 );

io_pos_assert( 'lee los pagos que ya existían', 3000.0, IO_POS_Payments::get_paid_total( $legacy ) );
io_pos_assert( 'calcula el saldo de esos pagos', 2000.0, IO_POS_Payments::get_balance( $legacy ) );

/* ---------------------------------------------------------------------- */
io_pos_section( 'Nombre de usuario del cliente' );

io_pos_assert( 'nombre y apellido', 'juan_perez', io_pos_build_username( 'Juan', 'Pérez' ) );
io_pos_assert( 'con acentos y espacios', 'jose_maria_garcia_lopez', io_pos_build_username( 'José María', 'García López' ) );
io_pos_assert( 'solo nombre', 'ana', io_pos_build_username( 'Ana', '' ) );
io_pos_assert( 'sin nombre usa la empresa', 'imprenta_online', io_pos_build_username( '', '', 'Imprenta Online' ) );
io_pos_assert( 'sin nada', 'cliente', io_pos_build_username( '', '', '' ) );

/* ---------------------------------------------------------------------- */
io_pos_section( 'Emisión del pedido' );

$check = new ReflectionMethod( 'IO_POS_Order_Builder', 'check_expected_total' );
$check->setAccessible( true );

$order_total = new WC_Order( array(), 1500 );

io_pos_assert( 'sin total esperado no valida', true, $check->invoke( null, $order_total, array() ) );
io_pos_assert( 'total coincidente', true, $check->invoke( null, $order_total, array( 'expected_total' => 1500 ) ) );
io_pos_assert( 'diferencia de redondeo aceptada', true, $check->invoke( null, $order_total, array( 'expected_total' => 1500.004 ) ) );

$mismatch = $check->invoke( null, $order_total, array( 'expected_total' => 1200 ) );
io_pos_assert( 'total distinto corta la venta', 'io_pos_total_mismatch', is_wp_error( $mismatch ) ? $mismatch->get_error_code() : '' );

update_option(
	IO_POS_Settings::OPTION,
	array(
		'payment_methods'       => "efectivo|Efectivo",
		'payment_cash_method'   => 'efectivo',
		'payment_allow_partial' => 'yes',
	)
);
$reset->setValue( null, null );

$add_payments = new ReflectionMethod( 'IO_POS_Order_Builder', 'add_payments' );
$add_payments->setAccessible( true );

$GLOBALS['io_pos_test_caps'] = array( 'io_pos_partial_payment' => true );

$paid_order = new WC_Order( array(), 2000 );
$result     = $add_payments->invoke( null, $paid_order, array( 'payments' => array( array( 'method' => 'efectivo', 'amount' => 2000 ) ) ) );

io_pos_assert( 'cobro completo aceptado', true, $result );
io_pos_assert( 'queda sin saldo', 0.0, IO_POS_Payments::get_balance( $paid_order ) );

$over = $add_payments->invoke( null, new WC_Order( array(), 1000 ), array( 'payments' => array( array( 'method' => 'efectivo', 'amount' => 1500 ) ) ) );
io_pos_assert( 'no se puede cobrar de más', 'io_pos_overpaid', is_wp_error( $over ) ? $over->get_error_code() : '' );

$partial = $add_payments->invoke( null, new WC_Order( array(), 1000 ), array( 'payments' => array( array( 'method' => 'efectivo', 'amount' => 400 ) ) ) );
io_pos_assert( 'seña aceptada con permiso', true, $partial );

$GLOBALS['io_pos_test_caps'] = array();

$denied = $add_payments->invoke( null, new WC_Order( array(), 1000 ), array( 'payments' => array( array( 'method' => 'efectivo', 'amount' => 400 ) ) ) );
io_pos_assert( 'seña sin permiso', 'io_pos_partial_not_allowed', is_wp_error( $denied ) ? $denied->get_error_code() : '' );

$GLOBALS['io_pos_test_caps'] = array( 'io_pos_partial_payment' => true );

update_option( IO_POS_Settings::OPTION, array( 'payment_allow_partial' => 'no' ) );
$reset->setValue( null, null );

$disabled = $add_payments->invoke( null, new WC_Order( array(), 1000 ), array( 'payments' => array( array( 'method' => 'efectivo', 'amount' => 400 ) ) ) );
io_pos_assert( 'seña desactivada en ajustes', 'io_pos_partial_disabled', is_wp_error( $disabled ) ? $disabled->get_error_code() : '' );

/* ---------------------------------------------------------------------- */
io_pos_section( 'Ajustes que no están en el formulario' );

update_option(
	IO_POS_Settings::OPTION,
	array(
		'terminal_page_id' => 42,
		'terminal_title'   => 'Mostrador',
	)
);
$reset->setValue( null, null );

$saved = IO_POS_Settings::sanitize( array( 'terminal_title' => 'Caja 1' ) );

io_pos_assert( 'no se pierde la página del mostrador', 42, $saved['terminal_page_id'] );
io_pos_assert( 'se guarda lo que sí vino', 'Caja 1', $saved['terminal_title'] );
io_pos_assert( 'los textos largos ausentes se conservan', IO_POS_Settings::get( 'payment_methods' ), $saved['payment_methods'] );

/* ---------------------------------------------------------------------- */
io_pos_section( 'Carga de las clases' );

$remaining = array(
	'class-io-pos-install.php'                => 'IO_POS_Install',
	'class-io-pos-terminal.php'               => 'IO_POS_Terminal',
	'class-io-pos-plugin.php'                 => 'IO_POS_Plugin',
	'rest/class-io-pos-rest-api.php'          => 'IO_POS_REST_API',
	'modules/class-io-pos-order-display.php'  => 'IO_POS_Order_Display',
	'modules/class-io-pos-emails.php'         => 'IO_POS_Emails',
	'modules/class-io-pos-yith-bridge.php'    => 'IO_POS_Yith_Bridge',
	'admin/class-io-pos-admin-orders.php'     => 'IO_POS_Admin_Orders',
	'admin/class-io-pos-admin-settings.php'   => 'IO_POS_Admin_Settings',
);

foreach ( $remaining as $file => $class ) {
	require_once IO_POS_INCLUDES . $file;

	io_pos_assert( 'se carga ' . $class, true, class_exists( $class ) );
}

io_pos_assert(
	'la API termina en barra',
	'https://ejemplo.test/wp-json/io-pos/v1/',
	IO_POS_REST_API::get_base_url()
);

io_pos_assert(
	'la ruta se pega bien a la base',
	'https://ejemplo.test/wp-json/io-pos/v1/products?page=1',
	IO_POS_REST_API::get_base_url() . 'products?page=1'
);

io_pos_assert( 'no hay permisos duplicados', count( IO_POS_Install::get_capabilities() ), count( array_unique( array_keys( IO_POS_Install::get_capabilities() ) ) ) );
io_pos_assert( 'el cajero solo recibe permisos que existen', array(), array_diff( IO_POS_Install::get_cashier_capabilities(), array_merge( array( 'read' ), array_keys( IO_POS_Install::get_capabilities() ) ) ) );

/* ---------------------------------------------------------------------- */
echo "\n";
printf( "%d pruebas correctas, %d con error\n", $results['passed'], $results['failed'] );

exit( $results['failed'] > 0 ? 1 : 0 );
