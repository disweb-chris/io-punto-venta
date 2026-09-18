/**
 * Mostrador — punto de venta de la imprenta.
 *
 * Sin dependencias: se dibuja con DOM a mano para que el plugin se instale
 * como un ZIP común, sin compilar nada.
 */
( function ( window, document ) {
	'use strict';

	var cfg = window.ioPosTerminal;

	if ( ! cfg ) {
		return;
	}

	var caps = cfg.user.capabilities || {};
	var deliveryConfig = cfg.delivery || {};
	var root = document.getElementById( 'io-pos-app' );
	var receiptNode = document.getElementById( 'io-pos-receipt' );

	var state = {
		view: 'sale',
		search: '',
		category: 0,
		products: [],
		page: 1,
		hasMore: false,
		loadingProducts: false,
		cart: [],
		customer: null,
		job: {},
		productionStatus: cfg.production.default || '',
		discount: { type: 'fixed', amount: 0, reason: '' },
		shipping: { amount: 0, label: '' },
		note: '',
		lastOrder: null,
		history: [],
		historyFilter: 'today',
		historySearch: '',
		busy: false,
		message: null
	};

	var searchTimer = null;
	var lineKey = 0;

	/* ------------------------------------------------------------------ *
	 * Utilidades
	 * ------------------------------------------------------------------ */

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );

		attrs = attrs || {};

		Object.keys( attrs ).forEach( function ( key ) {
			var value = attrs[ key ];

			if ( null === value || false === value || undefined === value ) {
				return;
			}

			if ( 'text' === key ) {
				node.textContent = value;
			} else if ( 'html' === key ) {
				node.innerHTML = value;
			} else if ( 'class' === key ) {
				node.className = value;
			} else if ( 0 === key.indexOf( 'on' ) && 'function' === typeof value ) {
				node.addEventListener( key.slice( 2 ).toLowerCase(), value );
			} else {
				node.setAttribute( key, value );
			}
		} );

		( children || [] ).forEach( function ( child ) {
			if ( null === child || undefined === child || false === child ) {
				return;
			}

			node.appendChild( 'string' === typeof child ? document.createTextNode( child ) : child );
		} );

		return node;
	}

	function clear( node ) {
		while ( node.firstChild ) {
			node.removeChild( node.firstChild );
		}

		return node;
	}

	function money( amount ) {
		var c = cfg.currency;
		var value = Math.abs( Number( amount ) || 0 ).toFixed( c.decimals );
		var parts = value.split( '.' );

		parts[ 0 ] = parts[ 0 ].replace( /\B(?=(\d{3})+(?!\d))/g, c.thousand );

		var text = parts.join( c.decimal );
		var sign = Number( amount ) < 0 ? '-' : '';

		if ( 'right' === c.position ) {
			return sign + text + c.symbol;
		}

		if ( 'right_space' === c.position ) {
			return sign + text + ' ' + c.symbol;
		}

		if ( 'left_space' === c.position ) {
			return sign + c.symbol + ' ' + text;
		}

		return sign + c.symbol + text;
	}

	function toNumber( value ) {
		var number = parseFloat( String( value ).replace( ',', '.' ) );

		return isNaN( number ) ? 0 : number;
	}

	function round( value ) {
		var factor = Math.pow( 10, cfg.currency.decimals );

		return Math.round( ( Number( value ) || 0 ) * factor ) / factor;
	}

	function todayISO() {
		var now = new Date();

		return [
			now.getFullYear(),
			( '0' + ( now.getMonth() + 1 ) ).slice( -2 ),
			( '0' + now.getDate() ).slice( -2 )
		].join( '-' );
	}

	function addDays( days ) {
		var date = new Date();

		date.setDate( date.getDate() + ( parseInt( days, 10 ) || 0 ) );

		return [
			date.getFullYear(),
			( '0' + ( date.getMonth() + 1 ) ).slice( -2 ),
			( '0' + date.getDate() ).slice( -2 )
		].join( '-' );
	}

	/* --- Fechas de entrega ------------------------------------------------ */

	function parseISO( iso ) {
		var parts = String( iso ).split( '-' );

		return new Date( parseInt( parts[ 0 ], 10 ), parseInt( parts[ 1 ], 10 ) - 1, parseInt( parts[ 2 ], 10 ) );
	}

	function toISO( date ) {
		return [
			date.getFullYear(),
			( '0' + ( date.getMonth() + 1 ) ).slice( -2 ),
			( '0' + date.getDate() ).slice( -2 )
		].join( '-' );
	}

	function shiftISO( iso, days ) {
		var date = parseISO( iso );

		date.setDate( date.getDate() + days );

		return toISO( date );
	}

	/**
	 * Si el taller trabaja ese día.
	 *
	 * @param {string} iso Fecha en formato aaaa-mm-dd.
	 * @return {boolean} Si es día laborable.
	 */
	function isWorkingDay( iso ) {
		var workdays = deliveryConfig.workdays || [ 1, 2, 3, 4, 5 ];
		var holidays = deliveryConfig.holidays || [];
		var weekday = parseISO( iso ).getDay();

		weekday = 0 === weekday ? 7 : weekday;

		if ( workdays.indexOf( weekday ) < 0 ) {
			return false;
		}

		return holidays.indexOf( iso ) < 0;
	}

	/**
	 * Suma días hábiles, cayendo siempre en un día laborable.
	 *
	 * Hace la misma cuenta que el servidor; el punto de partida ya viene
	 * corrido por la hora de corte, así que acá no hay que mirar el reloj.
	 *
	 * @param {string} startISO Desde cuándo.
	 * @param {number} days     Días hábiles a sumar.
	 * @return {string} La fecha resultante.
	 */
	function addBusinessDays( startISO, days ) {
		var iso = startISO;
		var added = 0;
		var guard = 0;

		days = Math.max( 0, parseInt( days, 10 ) || 0 );

		while ( added < days && guard < 3650 ) {
			iso = shiftISO( iso, 1 );
			guard++;

			if ( isWorkingDay( iso ) ) {
				added++;
			}
		}

		guard = 0;

		while ( ! isWorkingDay( iso ) && guard < 3650 ) {
			iso = shiftISO( iso, 1 );
			guard++;
		}

		return iso;
	}

	/**
	 * Días de producción de lo que hay en el carrito: manda el más lento.
	 *
	 * @return {number} Días hábiles.
	 */
	function cartProductionDays() {
		var fallback = parseInt( deliveryConfig.defaultDays, 10 ) || 0;
		var days = null;

		state.cart.forEach( function ( line ) {
			var lineDays = ( null === line.productionDays || undefined === line.productionDays )
				? fallback
				: parseInt( line.productionDays, 10 );

			if ( isNaN( lineDays ) ) {
				lineDays = fallback;
			}

			days = null === days ? lineDays : Math.max( days, lineDays );
		} );

		return null === days ? fallback : days;
	}

	function suggestedDeliveryDate() {
		if ( ! deliveryConfig.startDate ) {
			return addDays( 0 );
		}

		return addBusinessDays( deliveryConfig.startDate, cartProductionDays() );
	}

	/**
	 * Primeras fechas de entrega posibles.
	 *
	 * @param {number} count Cuántas.
	 * @return {string[]} Fechas en formato aaaa-mm-dd.
	 */
	function deliveryOptions( count ) {
		var options = [ suggestedDeliveryDate() ];

		while ( options.length < count ) {
			options.push( addBusinessDays( options[ options.length - 1 ], 1 ) );
		}

		return options;
	}

	function weekdayLabel( iso ) {
		var names = [ 'Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb' ];
		var date = parseISO( iso );

		return names[ date.getDay() ] + ' ' + date.getDate() + '/' + ( date.getMonth() + 1 );
	}

	function formatDate( iso ) {
		if ( ! iso ) {
			return '';
		}

		var parts = String( iso ).split( '-' );

		return 3 === parts.length ? parts[ 2 ] + '/' + parts[ 1 ] + '/' + parts[ 0 ] : iso;
	}

	/**
	 * Arma la dirección de una llamada a la API.
	 *
	 * Contempla los dos formatos de enlaces permanentes de WordPress: los
	 * bonitos (/wp-json/io-pos/v1/) y los simples (?rest_route=/io-pos/v1/),
	 * donde los parámetros se unen con & en vez de con ?.
	 *
	 * @param {string} path Ruta, con sus parámetros si los tiene.
	 * @return {string} La dirección completa.
	 */
	function apiUrl( path ) {
		var base = String( cfg.restUrl || '' ).replace( /\/+$/, '' );
		var parts = String( path ).split( '?' );
		var url = base + '/' + parts[ 0 ].replace( /^\/+/, '' );
		var query = parts[ 1 ] || '';

		if ( ! query ) {
			return url;
		}

		return url + ( url.indexOf( '?' ) >= 0 ? '&' : '?' ) + query;
	}

	function api( path, options ) {
		options = options || {};

		var url = apiUrl( path );
		var init = {
			method: options.method || 'GET',
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': cfg.nonce,
				'Content-Type': 'application/json'
			}
		};

		if ( options.data ) {
			init.body = JSON.stringify( options.data );
		}

		return window.fetch( url, init ).then( function ( response ) {
			return response.json().then( function ( body ) {
				if ( ! response.ok ) {
					throw new Error( ( body && body.message ) || 'Error inesperado del servidor.' );
				}

				return body;
			} ).catch( function ( error ) {
				if ( error instanceof SyntaxError ) {
					throw new Error( 'El servidor respondió algo que no pudimos leer.' );
				}

				throw error;
			} );
		} );
	}

	function notify( text, type ) {
		state.message = text ? { text: text, type: type || 'info' } : null;

		render();

		if ( text ) {
			window.setTimeout( function () {
				if ( state.message && state.message.text === text ) {
					state.message = null;
					render();
				}
			}, 4000 );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Carrito
	 * ------------------------------------------------------------------ */

	function cartSubtotal() {
		return round( state.cart.reduce( function ( total, line ) {
			return total + line.price * line.qty;
		}, 0 ) );
	}

	function discountValue() {
		var subtotal = cartSubtotal();
		var amount = toNumber( state.discount.amount );

		if ( amount <= 0 ) {
			return 0;
		}

		if ( 'percent' === state.discount.type ) {
			return round( Math.min( subtotal, subtotal * amount / 100 ) );
		}

		return round( Math.min( subtotal, amount ) );
	}

	function cartTotal() {
		return round( cartSubtotal() - discountValue() + toNumber( state.shipping.amount ) );
	}

	function addToCart( product, qty ) {
		if ( product.is_variable ) {
			openVariations( product );

			return;
		}

		var existing = null;

		state.cart.forEach( function ( line ) {
			if ( ! line.custom && line.productId === product.id && ! line.note ) {
				existing = line;
			}
		} );

		if ( existing ) {
			existing.qty += qty || 1;
		} else {
			state.cart.push( {
				key: ++lineKey,
				custom: false,
				productId: product.id,
				parentId: product.parent_id,
				name: product.name + ( product.description ? ' — ' + product.description : '' ),
				sku: product.sku,
				price: round( product.price ),
				qty: qty || 1,
				note: '',
				image: product.image,
				productionDays: ( undefined === product.production_days ) ? null : product.production_days
			} );
		}

		render();
	}

	function addCustomLine( data ) {
		state.cart.push( {
			key: ++lineKey,
			custom: true,
			productId: 0,
			name: data.name,
			sku: '',
			price: round( data.price ),
			qty: data.qty || 1,
			note: data.note || '',
			image: ''
		} );

		render();
	}

	function removeLine( key ) {
		state.cart = state.cart.filter( function ( line ) {
			return line.key !== key;
		} );

		render();
	}

	function resetSale() {
		state.cart = [];
		state.customer = null;
		state.job = {};
		state.productionStatus = cfg.production.default || '';
		state.discount = { type: 'fixed', amount: 0, reason: '' };
		state.shipping = { amount: 0, label: '' };
		state.note = '';
		state.lastOrder = null;
		state.search = '';
		state.products = [];

		loadProducts();
		render();
		focusSearch();
	}

	/* ------------------------------------------------------------------ *
	 * Productos
	 * ------------------------------------------------------------------ */

	function loadProducts( append ) {
		state.loadingProducts = true;

		if ( ! append ) {
			state.page = 1;
		}

		var params = [ 'page=' + state.page ];

		if ( state.search ) {
			params.push( 'search=' + encodeURIComponent( state.search ) );
		}

		if ( state.category ) {
			params.push( 'category=' + state.category );
		}

		render();

		api( 'products?' + params.join( '&' ) ).then( function ( body ) {
			state.products = append ? state.products.concat( body.products ) : body.products;
			state.hasMore = body.has_more;
			state.loadingProducts = false;

			render();
		} ).catch( function ( error ) {
			state.loadingProducts = false;

			notify( error.message, 'error' );
		} );
	}

	function onSearchInput( value ) {
		state.search = value;

		window.clearTimeout( searchTimer );

		if ( value && value.length < cfg.settings.minChars ) {
			return;
		}

		searchTimer = window.setTimeout( function () {
			loadProducts();
		}, 250 );
	}

	function focusSearch() {
		var input = document.getElementById( 'io-pos-search' );

		if ( input ) {
			input.focus();
			input.select();
		}
	}

	/* ------------------------------------------------------------------ *
	 * Modales
	 * ------------------------------------------------------------------ */

	function openModal( title, body, footer, className ) {
		closeModal();

		var overlay = el( 'div', { class: 'io-pos-modal__overlay', onclick: closeModal } );
		var content = el( 'div', { class: 'io-pos-modal__content' }, [
			el( 'div', { class: 'io-pos-modal__head' }, [
				el( 'h2', { class: 'io-pos-modal__title', text: title } ),
				el( 'button', { class: 'io-pos-modal__close', type: 'button', text: '×', onclick: closeModal } )
			] ),
			el( 'div', { class: 'io-pos-modal__body' }, [ body ] ),
			footer ? el( 'div', { class: 'io-pos-modal__footer' }, footer ) : null
		] );

		var modal = el( 'div', { class: 'io-pos-modal ' + ( className || '' ), id: 'io-pos-modal' }, [ overlay, content ] );

		document.body.appendChild( modal );

		var first = content.querySelector( 'input, select, textarea, button.io-pos-button--primary' );

		if ( first ) {
			first.focus();
		}

		return modal;
	}

	function closeModal() {
		var modal = document.getElementById( 'io-pos-modal' );

		if ( modal && modal.parentNode ) {
			modal.parentNode.removeChild( modal );
		}
	}

	function field( label, input ) {
		return el( 'label', { class: 'io-pos-field' }, [
			el( 'span', { class: 'io-pos-field__label', text: label } ),
			input
		] );
	}

	function button( text, onClick, variant ) {
		return el( 'button', {
			type: 'button',
			class: 'io-pos-button' + ( variant ? ' io-pos-button--' + variant : '' ),
			text: text,
			onclick: onClick
		} );
	}

	/* --- Variaciones --- */

	function openVariations( product ) {
		var body = el( 'div', { class: 'io-pos-variations', text: 'Cargando…' } );

		openModal( product.name, body, null, 'io-pos-modal--wide' );

		api( 'products/' + product.id + '/variations' ).then( function ( data ) {
			clear( body );

			if ( ! data.variations.length ) {
				body.appendChild( el( 'p', { text: 'Este producto no tiene variaciones cargadas.' } ) );

				return;
			}

			data.variations.forEach( function ( variation ) {
				body.appendChild(
					el( 'button', {
						type: 'button',
						class: 'io-pos-variation',
						onclick: function () {
							closeModal();
							addToCart( variation, 1 );
						}
					}, [
						el( 'span', { class: 'io-pos-variation__name', text: variation.description || variation.name } ),
						el( 'span', { class: 'io-pos-variation__price', text: money( variation.price ) } )
					] )
				);
			} );
		} ).catch( function ( error ) {
			clear( body ).appendChild( el( 'p', { class: 'io-pos-error', text: error.message } ) );
		} );
	}

	/* --- Línea del carrito --- */

	function openLine( line ) {
		var qtyInput = el( 'input', { type: 'number', min: '1', step: '1', value: line.qty, class: 'io-pos-input' } );
		var priceInput = el( 'input', {
			type: 'number',
			step: '0.01',
			min: '0',
			value: line.price,
			class: 'io-pos-input',
			disabled: ( caps.io_pos_edit_price || line.custom ) ? null : 'disabled'
		} );
		var noteInput = el( 'textarea', { rows: '2', class: 'io-pos-input' } );

		noteInput.value = line.note || '';

		var body = el( 'div', { class: 'io-pos-form' }, [
			field( 'Cantidad', qtyInput ),
			field( 'Precio unitario', priceInput ),
			field( 'Nota para producción', noteInput )
		] );

		openModal( line.name, body, [
			button( 'Quitar del pedido', function () {
				closeModal();
				removeLine( line.key );
			}, 'danger' ),
			button( 'Guardar', function () {
				var qty = Math.max( 1, parseInt( qtyInput.value, 10 ) || 1 );

				line.qty = qty;
				line.note = noteInput.value.trim();

				if ( caps.io_pos_edit_price || line.custom ) {
					line.price = round( toNumber( priceInput.value ) );
				}

				closeModal();
				render();
			}, 'primary' )
		] );
	}

	/* --- Trabajo a medida --- */

	function openCustomItem() {
		var nameInput = el( 'input', { type: 'text', class: 'io-pos-input', placeholder: 'Ej.: Cartelería 2x1 en lona' } );
		var priceInput = el( 'input', { type: 'number', step: '0.01', min: '0', class: 'io-pos-input', value: '0' } );
		var qtyInput = el( 'input', { type: 'number', min: '1', step: '1', class: 'io-pos-input', value: '1' } );
		var noteInput = el( 'textarea', { rows: '2', class: 'io-pos-input' } );
		var error = el( 'p', { class: 'io-pos-error' } );

		var body = el( 'div', { class: 'io-pos-form' }, [
			field( 'Descripción', nameInput ),
			field( 'Precio unitario', priceInput ),
			field( 'Cantidad', qtyInput ),
			field( 'Nota para producción', noteInput ),
			error
		] );

		openModal( 'Trabajo a medida', body, [
			button( 'Cancelar', closeModal ),
			button( 'Agregar', function () {
				if ( ! nameInput.value.trim() ) {
					error.textContent = 'Poné una descripción del trabajo.';

					return;
				}

				addCustomLine( {
					name: nameInput.value.trim(),
					price: toNumber( priceInput.value ),
					qty: Math.max( 1, parseInt( qtyInput.value, 10 ) || 1 ),
					note: noteInput.value.trim()
				} );

				closeModal();
			}, 'primary' )
		] );
	}

	/* --- Cliente --- */

	function openCustomer() {
		var searchInput = el( 'input', { type: 'search', class: 'io-pos-input', placeholder: 'Nombre, apellido, teléfono o empresa' } );
		var results = el( 'div', { class: 'io-pos-results' } );
		var timer = null;

		searchInput.addEventListener( 'input', function () {
			var term = searchInput.value.trim();

			window.clearTimeout( timer );

			if ( term.length < 2 ) {
				clear( results );

				return;
			}

			timer = window.setTimeout( function () {
				api( 'customers?search=' + encodeURIComponent( term ) ).then( function ( data ) {
					clear( results );

					if ( ! data.customers.length ) {
						results.appendChild( el( 'p', { class: 'io-pos-muted', text: 'Sin resultados.' } ) );

						return;
					}

					data.customers.forEach( function ( customer ) {
						results.appendChild(
							el( 'button', {
								type: 'button',
								class: 'io-pos-result',
								onclick: function () {
									state.customer = customer;
									closeModal();
									render();
								}
							}, [
								el( 'strong', { text: customer.name || customer.company } ),
								el( 'small', { text: [ customer.company, customer.phone, customer.email ].filter( Boolean ).join( ' · ' ) } )
							] )
						);
					} );
				} ).catch( function ( error ) {
					clear( results ).appendChild( el( 'p', { class: 'io-pos-error', text: error.message } ) );
				} );
			}, 250 );
		} );

		var body = el( 'div', { class: 'io-pos-form' }, [
			field( 'Buscar cliente', searchInput ),
			results
		] );

		var footer = [ button( 'Sin cliente', function () {
			state.customer = null;
			closeModal();
			render();
		} ) ];

		if ( caps.io_pos_manage_customers ) {
			footer.push( button( 'Cliente nuevo', openNewCustomer, 'primary' ) );
		}

		openModal( 'Cliente', body, footer );
	}

	function openNewCustomer() {
		var inputs = {
			first_name: el( 'input', { type: 'text', class: 'io-pos-input' } ),
			last_name: el( 'input', { type: 'text', class: 'io-pos-input' } ),
			company: el( 'input', { type: 'text', class: 'io-pos-input' } ),
			phone: el( 'input', { type: 'tel', class: 'io-pos-input' } ),
			email: el( 'input', { type: 'email', class: 'io-pos-input' } ),
			vat: el( 'input', { type: 'text', class: 'io-pos-input' } )
		};

		var error = el( 'p', { class: 'io-pos-error' } );

		var body = el( 'div', { class: 'io-pos-form io-pos-form--grid' }, [
			field( 'Nombre', inputs.first_name ),
			field( 'Apellido', inputs.last_name ),
			field( 'Empresa', inputs.company ),
			field( 'Teléfono', inputs.phone ),
			field( 'Correo', inputs.email ),
			field( 'CUIT / DNI', inputs.vat ),
			error
		] );

		openModal( 'Cliente nuevo', body, [
			button( 'Cancelar', openCustomer ),
			button( 'Guardar', function () {
				var data = {};

				Object.keys( inputs ).forEach( function ( key ) {
					data[ key ] = inputs[ key ].value.trim();
				} );

				if ( ! data.first_name && ! data.company ) {
					error.textContent = 'Poné al menos el nombre o la empresa.';

					return;
				}

				api( 'customers', { method: 'POST', data: { customer: data } } ).then( function ( body ) {
					state.customer = body.customer;

					closeModal();
					render();
				} ).catch( function ( err ) {
					error.textContent = err.message;
				} );
			}, 'primary' )
		] );
	}

	/* --- Datos del trabajo --- */

	function openJob() {
		var inputs = {};
		var body = el( 'div', { class: 'io-pos-form io-pos-form--grid' } );

		cfg.job.fields.forEach( function ( def ) {
			var input;
			var value = state.job[ def.key ] || '';

			if ( 'delivery_date' === def.key && ! value ) {
				value = suggestedDeliveryDate();
			}

			if ( 'textarea' === def.type ) {
				input = el( 'textarea', { rows: '2', class: 'io-pos-input' } );
				input.value = value;
			} else if ( 'select' === def.type ) {
				input = el( 'select', { class: 'io-pos-input' }, [ el( 'option', { value: '', text: 'Elegir…' } ) ] );

				def.options.forEach( function ( option ) {
					input.appendChild( el( 'option', { value: option, text: option } ) );
				} );

				input.value = value;
			} else {
				input = el( 'input', {
					type: 'number' === def.type ? 'number' : ( 'date' === def.type ? 'date' : 'text' ),
					class: 'io-pos-input'
				} );

				if ( 'number' === def.type ) {
					input.setAttribute( 'step', 'any' );
				}

				input.value = value;
			}

			inputs[ def.key ] = input;

			var wrapper = field( def.label + ( def.required ? ' *' : '' ), input );

			if ( 'delivery_date' === def.key ) {
				var shortcuts = el( 'div', { class: 'io-pos-shortcuts' } );

				var count = Math.min( 6, parseInt( deliveryConfig.maxOptions, 10 ) || 6 );

				deliveryOptions( count ).forEach( function ( option, index ) {
					var label = 0 === index
						? weekdayLabel( option ) + ' · lo antes posible'
						: weekdayLabel( option );

					shortcuts.appendChild( button( label, function () {
						input.value = option;
					}, 'chip' ) );
				} );

				wrapper.appendChild( shortcuts );
				wrapper.appendChild( el( 'small', {
					class: 'io-pos-modal__help',
					text: 'Calculado con los días de producción de lo que hay en el pedido.'
				} ) );
				wrapper.className += ' io-pos-field--wide';
			}

			if ( 'textarea' === def.type ) {
				wrapper.className += ' io-pos-field--wide';
			}

			body.appendChild( wrapper );
		} );

		var statusSelect = null;

		if ( cfg.production.enabled && cfg.production.statuses.length ) {
			statusSelect = el( 'select', { class: 'io-pos-input' } );

			cfg.production.statuses.forEach( function ( status ) {
				statusSelect.appendChild( el( 'option', { value: status.key, text: status.label } ) );
			} );

			statusSelect.value = state.productionStatus || cfg.production.default;

			body.appendChild( field( 'Estado de producción', statusSelect ) );
		}

		var error = el( 'p', { class: 'io-pos-error' } );

		body.appendChild( error );

		openModal( 'Datos del trabajo', body, [
			button( 'Vaciar', function () {
				state.job = {};
				closeModal();
				render();
			} ),
			button( 'Guardar', function () {
				var values = {};
				var missing = false;

				cfg.job.fields.forEach( function ( def ) {
					var value = String( inputs[ def.key ].value || '' ).trim();

					if ( def.required && ! value ) {
						missing = true;
					}

					if ( value ) {
						values[ def.key ] = value;
					}
				} );

				if ( missing ) {
					error.textContent = 'Completá los campos obligatorios.';

					return;
				}

				state.job = values;

				if ( statusSelect ) {
					state.productionStatus = statusSelect.value;
				}

				closeModal();
				render();
			}, 'primary' )
		], 'io-pos-modal--wide' );
	}

	/* --- Descuento y envío --- */

	function openExtras() {
		var typeSelect = el( 'select', { class: 'io-pos-input' }, [
			el( 'option', { value: 'fixed', text: 'Importe' } ),
			el( 'option', { value: 'percent', text: 'Porcentaje' } )
		] );

		typeSelect.value = state.discount.type;

		var amountInput = el( 'input', { type: 'number', step: '0.01', min: '0', class: 'io-pos-input', value: state.discount.amount || '' } );
		var reasonInput = el( 'input', { type: 'text', class: 'io-pos-input', value: state.discount.reason || '' } );
		var shippingInput = el( 'input', { type: 'number', step: '0.01', min: '0', class: 'io-pos-input', value: state.shipping.amount || '' } );
		var shippingLabel = el( 'input', { type: 'text', class: 'io-pos-input', value: state.shipping.label || '', placeholder: 'Envío' } );

		var body = el( 'div', { class: 'io-pos-form io-pos-form--grid' }, [
			caps.io_pos_discount ? field( 'Tipo de descuento', typeSelect ) : null,
			caps.io_pos_discount ? field( 'Descuento', amountInput ) : null,
			caps.io_pos_discount ? field( 'Motivo', reasonInput ) : null,
			field( 'Costo de envío', shippingInput ),
			field( 'Texto del envío', shippingLabel )
		] );

		openModal( 'Descuento y envío', body, [
			button( 'Cancelar', closeModal ),
			button( 'Aplicar', function () {
				if ( caps.io_pos_discount ) {
					state.discount = {
						type: typeSelect.value,
						amount: toNumber( amountInput.value ),
						reason: reasonInput.value.trim()
					};
				}

				state.shipping = {
					amount: toNumber( shippingInput.value ),
					label: shippingLabel.value.trim()
				};

				closeModal();
				render();
			}, 'primary' )
		] );
	}

	/* ------------------------------------------------------------------ *
	 * Cobro
	 * ------------------------------------------------------------------ */

	function openPayment() {
		if ( ! state.cart.length ) {
			notify( 'Cargá algo en el pedido antes de cobrar.', 'error' );

			return;
		}

		if ( cfg.job.enabled && cfg.job.required && ! state.job.delivery_date ) {
			notify( 'Cargá la fecha de entrega antes de cobrar.', 'error' );
			openJob();

			return;
		}

		var total = cartTotal();
		var rows = [ { method: cfg.settings.cashMethod, amount: total, typed: '' } ];
		var activeIndex = 0;
		var padFresh = true;

		var summary = el( 'div', { class: 'io-pos-pay__summary' } );
		var rowsNode = el( 'div', { class: 'io-pos-pay__rows' } );
		var error = el( 'p', { class: 'io-pos-error' } );

		function paidTotal() {
			return round( rows.reduce( function ( sum, row ) {
				return sum + toNumber( row.amount );
			}, 0 ) );
		}

		function applied() {
			return Math.min( paidTotal(), total );
		}

		function change() {
			return round( Math.max( 0, paidTotal() - total ) );
		}

		function balance() {
			return round( Math.max( 0, total - paidTotal() ) );
		}

		function drawSummary() {
			clear( summary );

			summary.appendChild( el( 'div', { class: 'io-pos-pay__total' }, [
				el( 'span', { text: 'Total a cobrar' } ),
				el( 'strong', { text: money( total ) } )
			] ) );

			summary.appendChild( el( 'div', { class: 'io-pos-pay__line' }, [
				el( 'span', { text: 'Cobrado' } ),
				el( 'strong', { text: money( applied() ) } )
			] ) );

			if ( change() > 0 ) {
				summary.appendChild( el( 'div', { class: 'io-pos-pay__line io-pos-pay__line--change' }, [
					el( 'span', { text: 'Vuelto' } ),
					el( 'strong', { text: money( change() ) } )
				] ) );
			}

			if ( balance() > 0 ) {
				summary.appendChild( el( 'div', { class: 'io-pos-pay__line io-pos-pay__line--balance' }, [
					el( 'span', { text: 'Saldo pendiente' } ),
					el( 'strong', { text: money( balance() ) } )
				] ) );
			}
		}

		function drawRows() {
			clear( rowsNode );

			rows.forEach( function ( row, index ) {
				var select = el( 'select', { class: 'io-pos-input' } );

				cfg.paymentMethods.forEach( function ( method ) {
					select.appendChild( el( 'option', { value: method.key, text: method.label } ) );
				} );

				select.value = row.method;
				select.addEventListener( 'change', function () {
					row.method = select.value;
				} );

				var amount = el( 'input', {
					type: 'number',
					step: '0.01',
					min: '0',
					class: 'io-pos-input io-pos-pay__amount',
					value: row.amount
				} );

				amount.addEventListener( 'focus', function () {
					activeIndex = index;
					padFresh = true;
				} );

				amount.addEventListener( 'input', function () {
					row.amount = toNumber( amount.value );
					row.typed = amount.value;
					padFresh = false;

					drawSummary();
				} );

				rowsNode.appendChild( el( 'div', { class: 'io-pos-pay__row' }, [
					select,
					amount,
					rows.length > 1 ? button( '×', function () {
						rows.splice( index, 1 );
						drawRows();
						drawSummary();
					}, 'icon' ) : null
				] ) );
			} );
		}

		function keypad() {
			var pad = el( 'div', { class: 'io-pos-keypad' } );

			[ '7', '8', '9', '4', '5', '6', '1', '2', '3', '0', '00', ',' ].forEach( function ( key ) {
				pad.appendChild( button( key, function () {
					var row = rows[ activeIndex ];

					// La primera tecla reemplaza el importe que venía puesto;
					// las siguientes lo van completando.
					var current = ( padFresh || ! row.amount ) ? '' : String( row.typed || row.amount );

					padFresh = false;

					if ( ',' === key ) {
						current = current.indexOf( '.' ) >= 0 ? current : ( current || '0' ) + '.';
					} else {
						current += key;
					}

					row.typed = current;
					row.amount = toNumber( current );

					drawRows();
					drawSummary();
				}, 'key' ) );
			} );

			pad.appendChild( button( 'Borrar', function () {
				rows[ activeIndex ].amount = 0;
				rows[ activeIndex ].typed = '';
				padFresh = true;

				drawRows();
				drawSummary();
			}, 'key' ) );

			pad.appendChild( button( 'Exacto', function () {
				var row = rows[ activeIndex ];

				row.amount = round( Math.max( 0, total - ( paidTotal() - toNumber( row.amount ) ) ) );
				row.typed = '';
				padFresh = true;

				drawRows();
				drawSummary();
			}, 'key' ) );

			return pad;
		}

		drawRows();
		drawSummary();

		var body = el( 'div', { class: 'io-pos-pay' }, [
			summary,
			rowsNode,
			el( 'div', { class: 'io-pos-pay__actions' }, [
				button( '+ Otro método', function () {
					rows.push( { method: cfg.settings.cashMethod, amount: balance(), typed: '' } );
					activeIndex = rows.length - 1;
					padFresh = true;

					drawRows();
					drawSummary();
				}, 'chip' ),
				cfg.settings.allowPartial && caps.io_pos_partial_payment ? button( 'Cobrar seña 50 %', function () {
					rows = [ { method: cfg.settings.cashMethod, amount: round( total / 2 ), typed: '' } ];
					activeIndex = 0;
					padFresh = true;

					drawRows();
					drawSummary();
				}, 'chip' ) : null,
				cfg.settings.allowPartial && caps.io_pos_partial_payment ? button( 'Sin cobrar ahora', function () {
					rows = [ { method: cfg.settings.cashMethod, amount: 0, typed: '' } ];
					activeIndex = 0;
					padFresh = true;

					drawRows();
					drawSummary();
				}, 'chip' ) : null
			] ),
			keypad(),
			error
		] );

		openModal( 'Cobrar', body, [
			button( 'Cancelar', closeModal ),
			button( 'Confirmar venta', function () {
				if ( balance() > 0 && ! ( cfg.settings.allowPartial && caps.io_pos_partial_payment ) ) {
					error.textContent = 'Tenés que cobrar el total del pedido.';

					return;
				}

				submitOrder( rows, change(), error );
			}, 'primary' )
		], 'io-pos-modal--pay' );
	}

	function submitOrder( rows, change, error ) {
		if ( state.busy ) {
			return;
		}

		state.busy = true;
		error.textContent = 'Emitiendo el pedido…';

		var total = cartTotal();
		var remaining = total;
		var payments = [];

		// Lo que se paga de más es vuelto, no se cobra.
		rows.forEach( function ( row ) {
			var amount = Math.min( toNumber( row.amount ), remaining );

			if ( amount > 0 ) {
				payments.push( { method: row.method, amount: round( amount ) } );
				remaining = round( remaining - amount );
			}
		} );

		var payload = {
			items: state.cart.map( function ( line ) {
				return {
					custom: line.custom,
					product_id: line.custom ? 0 : ( line.parentId || line.productId ),
					variation_id: line.custom ? 0 : ( line.parentId ? line.productId : 0 ),
					name: line.name,
					qty: line.qty,
					price: line.price,
					note: line.note
				};
			} ),
			customer_id: state.customer ? state.customer.id : 0,
			discount: state.discount,
			shipping: state.shipping,
			job: state.job,
			production_status: state.productionStatus,
			customer_note: state.note,
			payments: payments,
			change: change,
			expected_total: total
		};

		api( 'orders', { method: 'POST', data: payload } ).then( function ( body ) {
			state.busy = false;
			state.lastOrder = body.order;

			closeModal();
			render();

			maybePrintReceipt( body.order );
		} ).catch( function ( err ) {
			state.busy = false;
			error.textContent = err.message;
		} );
	}

	/* ------------------------------------------------------------------ *
	 * Comprobante
	 * ------------------------------------------------------------------ */

	function buildReceipt( order ) {
		var node = el( 'div', { class: 'io-pos-ticket' } );

		node.appendChild( el( 'div', { class: 'io-pos-ticket__head' }, [
			el( 'strong', { class: 'io-pos-ticket__store', text: cfg.store.name } ),
			cfg.store.details ? el( 'div', { class: 'io-pos-ticket__details', text: cfg.store.details } ) : null
		] ) );

		node.appendChild( el( 'div', { class: 'io-pos-ticket__meta' }, [
			el( 'div', { text: 'Pedido #' + order.number } ),
			el( 'div', { text: order.date_formatted } ),
			order.cashier ? el( 'div', { text: 'Atendió: ' + order.cashier } ) : null,
			order.customer && order.customer.name ? el( 'div', { text: 'Cliente: ' + order.customer.name } ) : null,
			order.customer && order.customer.phone ? el( 'div', { text: 'Tel: ' + order.customer.phone } ) : null
		] ) );

		var lines = el( 'table', { class: 'io-pos-ticket__items' } );

		( order.items || [] ).forEach( function ( item ) {
			var row = el( 'tr', {}, [
				el( 'td', { class: 'qty', text: String( item.qty ) } ),
				el( 'td', { class: 'name', text: item.name } ),
				el( 'td', { class: 'total', text: item.total_formatted } )
			] );

			lines.appendChild( row );

			if ( item.note ) {
				lines.appendChild( el( 'tr', {}, [
					el( 'td', {} ),
					el( 'td', { class: 'note', colspan: '2', text: item.note } )
				] ) );
			}
		} );

		node.appendChild( lines );

		var totals = el( 'div', { class: 'io-pos-ticket__totals' } );

		( order.fees || [] ).forEach( function ( fee ) {
			totals.appendChild( el( 'div', {}, [
				el( 'span', { text: fee.name } ),
				el( 'span', { text: fee.total_formatted } )
			] ) );
		} );

		( order.shipping || [] ).forEach( function ( line ) {
			totals.appendChild( el( 'div', {}, [
				el( 'span', { text: line.name } ),
				el( 'span', { text: line.total_formatted } )
			] ) );
		} );

		totals.appendChild( el( 'div', { class: 'io-pos-ticket__total' }, [
			el( 'span', { text: 'TOTAL' } ),
			el( 'span', { text: order.total_formatted } )
		] ) );

		( order.payments || [] ).forEach( function ( payment ) {
			totals.appendChild( el( 'div', {}, [
				el( 'span', { text: payment.label } ),
				el( 'span', { text: payment.amount_formatted } )
			] ) );
		} );

		if ( order.change > 0 ) {
			totals.appendChild( el( 'div', {}, [
				el( 'span', { text: 'Vuelto' } ),
				el( 'span', { text: order.change_formatted } )
			] ) );
		}

		if ( order.balance > 0 ) {
			totals.appendChild( el( 'div', { class: 'io-pos-ticket__balance' }, [
				el( 'span', { text: 'SALDO PENDIENTE' } ),
				el( 'span', { text: order.balance_formatted } )
			] ) );
		}

		node.appendChild( totals );

		if ( cfg.settings.receiptShowJob && order.job && order.job.length ) {
			var job = el( 'div', { class: 'io-pos-ticket__job' } );

			job.appendChild( el( 'strong', { text: 'Datos del trabajo' } ) );

			order.job.forEach( function ( detail ) {
				if ( false === detail.receipt ) {
					return;
				}

				job.appendChild( el( 'div', {}, [
					el( 'span', { text: detail.label + ': ' } ),
					el( 'span', { text: detail.value } )
				] ) );
			} );

			node.appendChild( job );
		}

		if ( order.note ) {
			node.appendChild( el( 'div', { class: 'io-pos-ticket__note', text: order.note } ) );
		}

		if ( cfg.store.footer ) {
			node.appendChild( el( 'div', { class: 'io-pos-ticket__footer', text: cfg.store.footer } ) );
		}

		return node;
	}

	/**
	 * Imprime solo si el ajuste lo pide.
	 *
	 * La usan los cierres automáticos (cobrar una venta, cobrar un saldo). Los
	 * botones de imprimir llaman a printReceipt directo, porque ahí la persona
	 * lo está pidiendo.
	 *
	 * @param {Object} order El pedido.
	 * @return {boolean} Si se mandó a imprimir.
	 */
	function maybePrintReceipt( order ) {
		if ( ! cfg.settings.receiptAutoPrint ) {
			return false;
		}

		printReceipt( order );

		return true;
	}

	function printReceipt( order ) {
		clear( receiptNode ).appendChild( buildReceipt( order ) );

		window.setTimeout( function () {
			window.print();
		}, 120 );
	}

	/* ------------------------------------------------------------------ *
	 * Historial
	 * ------------------------------------------------------------------ */

	function loadHistory() {
		state.busy = true;

		render();

		var params = [ 'filter=' + state.historyFilter ];

		if ( state.historySearch ) {
			params.push( 'search=' + encodeURIComponent( state.historySearch ) );
		}

		api( 'orders?' + params.join( '&' ) ).then( function ( body ) {
			state.history = body.orders;
			state.busy = false;

			render();
		} ).catch( function ( error ) {
			state.busy = false;

			notify( error.message, 'error' );
		} );
	}

	function openOrder( id ) {
		var body = el( 'div', { text: 'Cargando…' } );

		openModal( 'Pedido', body, null, 'io-pos-modal--wide' );

		api( 'orders/' + id ).then( function ( data ) {
			var order = data.order;

			clear( body );

			body.appendChild( buildReceipt( order ) );

			var footer = document.querySelector( '#io-pos-modal .io-pos-modal__footer' );
			var actions = [ button( 'Imprimir', function () {
				printReceipt( order );
			} ) ];

			if ( order.balance > 0 && caps.io_pos_collect_balance ) {
				actions.push( button( 'Cobrar saldo', function () {
					openCollectBalance( order );
				}, 'primary' ) );
			}

			if ( ! footer ) {
				footer = el( 'div', { class: 'io-pos-modal__footer' } );

				document.querySelector( '#io-pos-modal .io-pos-modal__content' ).appendChild( footer );
			}

			clear( footer );

			actions.forEach( function ( action ) {
				footer.appendChild( action );
			} );
		} ).catch( function ( error ) {
			clear( body ).appendChild( el( 'p', { class: 'io-pos-error', text: error.message } ) );
		} );
	}

	function openCollectBalance( order ) {
		var select = el( 'select', { class: 'io-pos-input' } );

		cfg.paymentMethods.forEach( function ( method ) {
			select.appendChild( el( 'option', { value: method.key, text: method.label } ) );
		} );

		select.value = cfg.settings.cashMethod;

		var amount = el( 'input', { type: 'number', step: '0.01', min: '0', class: 'io-pos-input', value: order.balance } );
		var error = el( 'p', { class: 'io-pos-error' } );

		var body = el( 'div', { class: 'io-pos-form' }, [
			el( 'p', { text: 'Pedido #' + order.number + ' — saldo pendiente ' + order.balance_formatted } ),
			field( 'Método', select ),
			field( 'Importe', amount ),
			error
		] );

		openModal( 'Cobrar saldo', body, [
			button( 'Cancelar', closeModal ),
			button( 'Registrar cobro', function () {
				api( 'orders/' + order.id + '/payments', {
					method: 'POST',
					data: { method: select.value, amount: toNumber( amount.value ) }
				} ).then( function ( data ) {
					closeModal();
					notify( 'Cobro registrado.', 'success' );
					maybePrintReceipt( data.order );
					loadHistory();
				} ).catch( function ( err ) {
					error.textContent = err.message;
				} );
			}, 'primary' )
		] );
	}

	/* ------------------------------------------------------------------ *
	 * Pantallas
	 * ------------------------------------------------------------------ */

	function renderHeader() {
		return el( 'header', { class: 'io-pos-header' }, [
			el( 'div', { class: 'io-pos-header__brand', text: cfg.title } ),
			el( 'nav', { class: 'io-pos-header__nav' }, [
				button( 'Vender', function () {
					state.view = 'sale';
					render();
					focusSearch();
				}, 'sale' === state.view ? 'nav-active' : 'nav' ),
				caps.io_pos_view_history ? button( 'Historial', function () {
					state.view = 'history';
					loadHistory();
				}, 'history' === state.view ? 'nav-active' : 'nav' ) : null
			] ),
			el( 'div', { class: 'io-pos-header__user' }, [
				el( 'span', { text: cfg.user.name } ),
				cfg.adminUrl ? el( 'a', {
					class: 'io-pos-header__link',
					href: cfg.adminUrl,
					target: '_blank',
					rel: 'noopener',
					text: cfg.adminLabel || 'Pedidos'
				} ) : null,
				el( 'a', { class: 'io-pos-header__link', href: cfg.logoutUrl, text: 'Salir' } )
			] )
		] );
	}

	function renderProducts() {
		var searchInput = el( 'input', {
			type: 'search',
			id: 'io-pos-search',
			class: 'io-pos-search',
			placeholder: 'Buscar por nombre, código o descripción…',
			autocomplete: 'off',
			value: state.search
		} );

		searchInput.addEventListener( 'input', function () {
			onSearchInput( searchInput.value );
		} );

		searchInput.addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' === event.key && 1 === state.products.length ) {
				event.preventDefault();
				addToCart( state.products[ 0 ], 1 );

				searchInput.value = '';
				state.search = '';

				loadProducts();
			}
		} );

		var categories = el( 'div', { class: 'io-pos-categories' } );

		categories.appendChild( button( 'Todos', function () {
			state.category = 0;
			loadProducts();
		}, 0 === state.category ? 'chip-active' : 'chip' ) );

		cfg.categories.forEach( function ( category ) {
			categories.appendChild( button( category.name, function () {
				state.category = category.id;
				state.search = '';
				loadProducts();
			}, state.category === category.id ? 'chip-active' : 'chip' ) );
		} );

		var grid = el( 'div', { class: 'io-pos-grid' } );

		if ( cfg.settings.allowCustomItems && caps.io_pos_custom_item ) {
			grid.appendChild(
				el( 'button', { type: 'button', class: 'io-pos-card io-pos-card--custom', onclick: openCustomItem }, [
					el( 'span', { class: 'io-pos-card__plus', text: '+' } ),
					el( 'span', { class: 'io-pos-card__name', text: 'Trabajo a medida' } )
				] )
			);
		}

		state.products.forEach( function ( product ) {
			var children = [];

			if ( cfg.settings.showImages ) {
				children.push(
					product.image
						? el( 'img', { class: 'io-pos-card__image', src: product.image, alt: '', loading: 'lazy' } )
						: el( 'span', { class: 'io-pos-card__image io-pos-card__image--empty' } )
				);
			}

			children.push( el( 'span', { class: 'io-pos-card__name', text: product.name } ) );

			if ( product.sku ) {
				children.push( el( 'span', { class: 'io-pos-card__sku', text: product.sku } ) );
			}

			children.push( el( 'span', { class: 'io-pos-card__price', text: product.has_price ? money( product.price ) : 'A presupuestar' } ) );

			if ( product.manage_stock ) {
				children.push( el( 'span', { class: 'io-pos-card__stock', text: 'Stock: ' + product.stock_quantity } ) );
			}

			grid.appendChild(
				el( 'button', {
					type: 'button',
					class: 'io-pos-card' + ( 'outofstock' === product.stock_status ? ' io-pos-card--outofstock' : '' ),
					onclick: function () {
						addToCart( product, 1 );
					}
				}, children )
			);
		} );

		if ( state.loadingProducts ) {
			grid.appendChild( el( 'div', { class: 'io-pos-muted', text: 'Buscando…' } ) );
		} else if ( ! state.products.length ) {
			grid.appendChild( el( 'div', { class: 'io-pos-muted', text: 'No encontramos productos con ese criterio.' } ) );
		}

		var footer = null;

		if ( state.hasMore && ! state.search ) {
			footer = el( 'div', { class: 'io-pos-more' }, [
				button( 'Ver más productos', function () {
					state.page++;
					loadProducts( true );
				} )
			] );
		}

		return el( 'section', { class: 'io-pos-products' }, [
			el( 'div', { class: 'io-pos-products__head' }, [ searchInput ] ),
			categories,
			grid,
			footer
		] );
	}

	/**
	 * Dibuja una línea del carrito.
	 *
	 * La cantidad y el precio se pueden tipear: se guardan al salir del campo o
	 * al apretar Enter, así escribir "10" no redibuja en cada tecla.
	 *
	 * @param {Object} line La línea.
	 * @return {HTMLElement} La línea dibujada.
	 */
	function renderLine( line ) {
		var canEditPrice = caps.io_pos_edit_price || line.custom;

		var qtyInput = el( 'input', {
			type: 'number',
			inputmode: 'numeric',
			min: '1',
			step: '1',
			id: 'io-pos-qty-' + line.key,
			class: 'io-pos-line__input io-pos-line__input--qty',
			value: line.qty
		} );

		var priceInput = el( 'input', {
			type: 'number',
			inputmode: 'decimal',
			min: '0',
			step: '0.01',
			id: 'io-pos-price-' + line.key,
			class: 'io-pos-line__input io-pos-line__input--price',
			value: line.price,
			disabled: canEditPrice ? null : 'disabled',
			title: canEditPrice ? '' : 'No tenés permiso para cambiar precios'
		} );

		function commit() {
			var qty = parseInt( qtyInput.value, 10 );

			line.qty = qty > 0 ? qty : 1;

			if ( canEditPrice ) {
				line.price = Math.max( 0, round( toNumber( priceInput.value ) ) );
			}

			render();
		}

		[ qtyInput, priceInput ].forEach( function ( input ) {
			input.addEventListener( 'change', commit );
			input.addEventListener( 'focus', function () {
				input.select();
			} );
			input.addEventListener( 'keydown', function ( event ) {
				if ( 'Enter' === event.key ) {
					event.preventDefault();
					input.blur();
				}
			} );
		} );

		return el( 'div', { class: 'io-pos-line' }, [
			el( 'div', { class: 'io-pos-line__top' }, [
				el( 'button', {
					type: 'button',
					class: 'io-pos-line__name',
					title: 'Editar la nota de producción',
					onclick: function () {
						openLine( line );
					}
				}, [
					el( 'span', { text: line.name } ),
					line.sku ? el( 'small', { class: 'io-pos-line__sku', text: line.sku } ) : null
				] ),
				el( 'button', {
					type: 'button',
					class: 'io-pos-line__remove',
					title: 'Quitar del pedido',
					text: '×',
					onclick: function () {
						removeLine( line.key );
					}
				} )
			] ),
			line.note ? el( 'div', { class: 'io-pos-line__note', text: line.note } ) : null,
			el( 'div', { class: 'io-pos-line__row' }, [
				button( '−', function () {
					if ( line.qty > 1 ) {
						line.qty--;
						render();
					} else {
						removeLine( line.key );
					}
				}, 'qty' ),
				qtyInput,
				button( '+', function () {
					line.qty++;
					render();
				}, 'qty' ),
				el( 'span', { class: 'io-pos-line__times', text: '×' } ),
				priceInput,
				el( 'span', { class: 'io-pos-line__total', text: money( line.price * line.qty ) } )
			] )
		] );
	}

	function renderCart() {
		var lines = el( 'div', { class: 'io-pos-cart__lines' } );

		if ( ! state.cart.length ) {
			lines.appendChild( el( 'p', { class: 'io-pos-muted', text: 'Todavía no cargaste nada.' } ) );
		}

		state.cart.forEach( function ( line ) {
			lines.appendChild( renderLine( line ) );
		} );

		var totals = el( 'div', { class: 'io-pos-totals' } );
		var discount = discountValue();
		var shipping = toNumber( state.shipping.amount );

		totals.appendChild( el( 'div', { class: 'io-pos-totals__line' }, [
			el( 'span', { text: 'Subtotal' } ),
			el( 'span', { text: money( cartSubtotal() ) } )
		] ) );

		if ( discount > 0 ) {
			totals.appendChild( el( 'div', { class: 'io-pos-totals__line' }, [
				el( 'span', { text: 'Descuento' } ),
				el( 'span', { text: '-' + money( discount ) } )
			] ) );
		}

		if ( shipping > 0 ) {
			totals.appendChild( el( 'div', { class: 'io-pos-totals__line' }, [
				el( 'span', { text: state.shipping.label || 'Envío' } ),
				el( 'span', { text: money( shipping ) } )
			] ) );
		}

		totals.appendChild( el( 'div', { class: 'io-pos-totals__line io-pos-totals__line--total' }, [
			el( 'span', { text: 'Total' } ),
			el( 'span', { text: money( cartTotal() ) } )
		] ) );

		var customerText = state.customer
			? ( state.customer.name || state.customer.company )
			: cfg.settings.customerLabel;

		var jobText = state.job.delivery_date
			? 'Entrega: ' + formatDate( state.job.delivery_date )
			: 'Sin fecha de entrega';

		return el( 'section', { class: 'io-pos-cart' }, [
			el( 'div', { class: 'io-pos-cart__head' }, [
				el( 'button', { type: 'button', class: 'io-pos-chip-button', onclick: openCustomer }, [
					el( 'small', { text: 'Cliente' } ),
					el( 'strong', { text: customerText } )
				] ),
				cfg.job.enabled ? el( 'button', {
					type: 'button',
					class: 'io-pos-chip-button' + ( state.job.delivery_date ? ' is-set' : '' ),
					onclick: openJob
				}, [
					el( 'small', { text: 'Trabajo' } ),
					el( 'strong', { text: jobText } )
				] ) : null
			] ),
			lines,
			totals,
			el( 'div', { class: 'io-pos-cart__actions' }, [
				button( 'Vaciar', function () {
					if ( state.cart.length && window.confirm( '¿Vaciar el pedido?' ) ) {
						resetSale();
					}
				} ),
				button( 'Extras', openExtras ),
				button( 'Cobrar · ' + money( cartTotal() ), openPayment, 'primary' )
			] )
		] );
	}

	function renderSuccess() {
		var order = state.lastOrder;

		return el( 'section', { class: 'io-pos-success' }, [
			el( 'div', { class: 'io-pos-success__box' }, [
				el( 'h2', { text: 'Pedido #' + order.number + ' emitido' } ),
				el( 'p', { class: 'io-pos-success__total', text: order.total_formatted } ),
				order.balance > 0 ? el( 'p', { class: 'io-pos-success__balance', text: 'Saldo pendiente: ' + order.balance_formatted } ) : null,
				order.delivery_formatted ? el( 'p', { text: 'Entrega: ' + order.delivery_formatted } ) : null,
				el( 'div', { class: 'io-pos-success__actions' }, [
					button( 'Imprimir comprobante', function () {
						printReceipt( order );
					} ),
					button( 'Nueva venta', resetSale, 'primary' )
				] )
			] )
		] );
	}

	function renderHistory() {
		var searchInput = el( 'input', {
			type: 'search',
			id: 'io-pos-history-search',
			class: 'io-pos-search',
			placeholder: 'Buscar por número de pedido o cliente…',
			value: state.historySearch
		} );

		searchInput.addEventListener( 'input', function () {
			state.historySearch = searchInput.value;
		} );

		searchInput.addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' === event.key ) {
				state.historySearch = searchInput.value.trim();

				loadHistory();
			}
		} );

		var filters = el( 'div', { class: 'io-pos-categories' } );

		[
			[ 'today', 'Hoy' ],
			[ 'mine', 'Mis ventas' ],
			[ 'balance', 'Con saldo' ],
			[ 'all', 'Todas' ]
		].forEach( function ( pair ) {
			filters.appendChild( button( pair[ 1 ], function () {
				state.historyFilter = pair[ 0 ];

				loadHistory();
			}, state.historyFilter === pair[ 0 ] ? 'chip-active' : 'chip' ) );
		} );

		var list = el( 'div', { class: 'io-pos-history' } );

		if ( state.busy ) {
			list.appendChild( el( 'p', { class: 'io-pos-muted', text: 'Cargando…' } ) );
		} else if ( ! state.history.length ) {
			list.appendChild( el( 'p', { class: 'io-pos-muted', text: 'No hay ventas con ese filtro.' } ) );
		}

		state.history.forEach( function ( order ) {
			list.appendChild(
				el( 'button', {
					type: 'button',
					class: 'io-pos-history__row' + ( order.balance > 0 ? ' has-balance' : '' ),
					onclick: function () {
						openOrder( order.id );
					}
				}, [
					el( 'span', { class: 'io-pos-history__number', text: '#' + order.number } ),
					el( 'span', { class: 'io-pos-history__customer', text: order.customer.name } ),
					el( 'span', { class: 'io-pos-history__date', text: order.date_formatted } ),
					el( 'span', { class: 'io-pos-history__total', text: order.total_formatted } ),
					order.balance > 0
						? el( 'span', { class: 'io-pos-history__balance', text: 'Saldo ' + order.balance_formatted } )
						: el( 'span', { class: 'io-pos-history__paid', text: 'Cobrado' } )
				] )
			);
		} );

		return el( 'section', { class: 'io-pos-history-view' }, [
			el( 'div', { class: 'io-pos-products__head' }, [ searchInput ] ),
			filters,
			list
		] );
	}

	function render() {
		// Al redibujar se pierde el foco: guardamos dónde estaba el cursor para
		// que se pueda seguir escribiendo en el buscador sin saltos.
		var active = document.activeElement;
		var activeId = active && active.id ? active.id : null;
		var selStart = null;
		var selEnd = null;

		if ( activeId ) {
			try {
				selStart = active.selectionStart;
				selEnd = active.selectionEnd;
			} catch ( error ) {
				selStart = null;
			}
		}

		clear( root );

		var children = [ renderHeader() ];

		if ( state.message ) {
			children.push( el( 'div', { class: 'io-pos-message io-pos-message--' + state.message.type, text: state.message.text } ) );
		}

		if ( 'history' === state.view ) {
			children.push( renderHistory() );
		} else if ( state.lastOrder ) {
			children.push( renderSuccess() );
		} else {
			children.push( el( 'main', { class: 'io-pos-main' }, [ renderProducts(), renderCart() ] ) );
		}

		children.forEach( function ( child ) {
			root.appendChild( child );
		} );

		if ( activeId ) {
			var restored = document.getElementById( activeId );

			if ( restored ) {
				restored.focus();

				if ( null !== selStart ) {
					try {
						restored.setSelectionRange( selStart, selEnd );
					} catch ( error ) {
						// Hay campos que no admiten mover el cursor; no importa.
					}
				}
			}
		}
	}

	/* ------------------------------------------------------------------ *
	 * Atajos de teclado
	 * ------------------------------------------------------------------ */

	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' === event.key ) {
			closeModal();

			return;
		}

		if ( 'F2' === event.key ) {
			event.preventDefault();
			focusSearch();

			return;
		}

		if ( 'F4' === event.key && 'sale' === state.view && ! state.lastOrder ) {
			event.preventDefault();
			openPayment();
		}
	} );

	render();
	loadProducts();
	focusSearch();
} )( window, document );
