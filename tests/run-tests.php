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
		'pendiente'  => 'Pendiente',
		'diseno'     => 'En diseño',
		'aprobacion' => 'Esperando aprobación',
		'produccion' => 'En producción',
		'listo'      => 'Listo para entregar',
		'entregado'  => 'Entregado',
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
		IO_POS_Job::META_BALANCE       => '1500',
	)
);

IO_POS_Job::sanitize_order_meta( $order );
$meta = $order->get_all_meta();

io_pos_assert( 'fecha normalizada', '2026-09-30', $meta[ IO_POS_Job::META_DELIVERY_DATE ] );
io_pos_assert( 'opción inválida descartada', false, isset( $meta[ IO_POS_Job::META_DELIVERY_TIME ] ) );
io_pos_assert( 'texto recortado', 'Cartulina 300g', $meta['_io_pos_field_material'] );
io_pos_assert( 'opción válida conservada', 'Laminado mate', $meta['_io_pos_field_terminacion'] );
io_pos_assert( 'número normalizado', '12.5', $meta['_io_pos_field_cantidad'] );
io_pos_assert( 'estado inválido pasa al inicial', 'pendiente', $meta[ IO_POS_Job::META_STATUS ] );
io_pos_assert( 'importe con decimales', '1500.00', $meta[ IO_POS_Job::META_BALANCE ] );

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
echo "\n";
printf( "%d pruebas correctas, %d con error\n", $results['passed'], $results['failed'] );

exit( $results['failed'] > 0 ? 1 : 0 );
