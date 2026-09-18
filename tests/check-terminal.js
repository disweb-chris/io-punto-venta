/**
 * Pruebas de la pantalla del mostrador.
 *
 * Extrae funciones del archivo que realmente se publica y las ejecuta, para no
 * probar una copia que se pueda desincronizar del original.
 *
 * Uso: node tests/check-terminal.js
 */
'use strict';

const fs = require( 'fs' );
const path = require( 'path' );

const source = fs.readFileSync( path.join( __dirname, '..', 'assets', 'js', 'terminal.js' ), 'utf8' );

let passed = 0;
let failed = 0;

function assert( name, expected, actual ) {
	if ( expected === actual ) {
		passed++;
		console.log( `  ok   ${ name }` );

		return;
	}

	failed++;
	console.log( `  FAIL ${ name }` );
	console.log( `       esperado: ${ expected }` );
	console.log( `       obtenido: ${ actual }` );
}

/**
 * Saca una función del archivo original, por nombre.
 *
 * @param {string} name Nombre de la función.
 * @return {string} El código de la función.
 */
function extract( name ) {
	const start = source.indexOf( `function ${ name }(` );

	if ( start < 0 ) {
		throw new Error( `No encontramos la función ${ name } en terminal.js` );
	}

	let depth = 0;
	let started = false;

	for ( let i = start; i < source.length; i++ ) {
		if ( '{' === source[ i ] ) {
			depth++;
			started = true;
		} else if ( '}' === source[ i ] ) {
			depth--;

			if ( started && 0 === depth ) {
				return source.slice( start, i + 1 );
			}
		}
	}

	throw new Error( `No pudimos leer la función ${ name }` );
}

function buildApiUrl( restUrl ) {
	// eslint-disable-next-line no-new-func
	const factory = new Function( 'cfg', `${ extract( 'apiUrl' ) }; return apiUrl;` );

	return factory( { restUrl } );
}

console.log( '\nDirecciones de la API (enlaces bonitos)' );

let apiUrl = buildApiUrl( 'https://ejemplo.test/wp-json/io-pos/v1/' );

assert(
	'ruta simple',
	'https://ejemplo.test/wp-json/io-pos/v1/customers',
	apiUrl( 'customers' )
);

assert(
	'ruta con parámetros',
	'https://ejemplo.test/wp-json/io-pos/v1/products?page=1&search=tarjetas',
	apiUrl( 'products?page=1&search=tarjetas' )
);

assert(
	'ruta anidada',
	'https://ejemplo.test/wp-json/io-pos/v1/orders/123/payments',
	apiUrl( 'orders/123/payments' )
);

assert(
	'aunque falte la barra final en la base',
	'https://ejemplo.test/wp-json/io-pos/v1/products?page=1',
	buildApiUrl( 'https://ejemplo.test/wp-json/io-pos/v1' )( 'products?page=1' )
);

console.log( '\nDirecciones de la API (enlaces simples)' );

apiUrl = buildApiUrl( 'https://ejemplo.test/?rest_route=/io-pos/v1/' );

assert(
	'ruta simple',
	'https://ejemplo.test/?rest_route=/io-pos/v1/customers',
	apiUrl( 'customers' )
);

assert(
	'los parámetros se unen con &',
	'https://ejemplo.test/?rest_route=/io-pos/v1/products&page=1&search=tarjetas',
	apiUrl( 'products?page=1&search=tarjetas' )
);

console.log( '\nDías hábiles (misma cuenta que el servidor)' );

const businessCfg = {
	delivery: {
		workdays: [ 1, 2, 3, 4, 5 ],
		holidays: [ '2026-09-21' ],
		defaultDays: 2
	}
};

const businessDays = new Function(
	'deliveryConfig',
	`${ extract( 'parseISO' ) }
	${ extract( 'toISO' ) }
	${ extract( 'shiftISO' ) }
	${ extract( 'isWorkingDay' ) }
	${ extract( 'addBusinessDays' ) }
	return addBusinessDays;`
)( businessCfg.delivery );

assert( 'cero días desde un viernes', '2026-09-18', businessDays( '2026-09-18', 0 ) );
assert( 'cero días desde un sábado', '2026-09-22', businessDays( '2026-09-19', 0 ) );
assert( 'un día saltea finde y feriado', '2026-09-22', businessDays( '2026-09-18', 1 ) );
assert( 'dos días', '2026-09-23', businessDays( '2026-09-18', 2 ) );
assert( 'cinco días', '2026-09-28', businessDays( '2026-09-18', 5 ) );

console.log( '\nImpresión del comprobante' );

/**
 * Arma maybePrintReceipt con un ajuste dado y un impresor de mentira.
 *
 * @param {boolean} autoPrint Si el ajuste está activado.
 * @return {Object} La función y lo que se mandó a imprimir.
 */
function buildMaybePrint( autoPrint ) {
	const impreso = { order: null, veces: 0 };

	const fn = new Function(
		'cfg',
		'printReceipt',
		`${ extract( 'maybePrintReceipt' ) }; return maybePrintReceipt;`
	)(
		{ settings: { receiptAutoPrint: autoPrint } },
		function ( order ) {
			impreso.order = order;
			impreso.veces++;
		}
	);

	return { fn, impreso };
}

const apagado = buildMaybePrint( false );

assert( 'con el ajuste apagado no imprime', false, apagado.fn( { id: 1 } ) );
assert( 'y no llama al impresor', 0, apagado.impreso.veces );

const encendido = buildMaybePrint( true );

assert( 'con el ajuste encendido imprime', true, encendido.fn( { id: 7 } ) );
assert( 'una sola vez', 1, encendido.impreso.veces );
assert( 'y con el pedido que le pasaron', 7, encendido.impreso.order.id );

// Las dos impresiones automáticas —cerrar una venta y cobrar un saldo— tienen
// que pasar por el ajuste. Los botones de imprimir sí llaman directo.
assert(
	'cerrar la venta respeta el ajuste',
	true,
	source.includes( 'maybePrintReceipt( body.order )' )
);
assert(
	'cobrar un saldo también',
	true,
	source.includes( 'maybePrintReceipt( data.order )' )
);

console.log( '\nFormato de importes' );

const money = new Function(
	'cfg',
	`${ extract( 'money' ) }; return money;`
)( {
	currency: {
		symbol: '$',
		decimals: 2,
		decimal: ',',
		thousand: '.',
		position: 'left_space'
	}
} );

assert( 'miles y decimales', '$ 1.234,50', money( 1234.5 ) );
assert( 'cero', '$ 0,00', money( 0 ) );
assert( 'negativo', '-$ 500,00', money( -500 ) );
assert( 'millones', '$ 1.000.000,00', money( 1000000 ) );

console.log( `\n${ passed } pruebas correctas, ${ failed } con error` );

process.exit( failed > 0 ? 1 : 0 );
