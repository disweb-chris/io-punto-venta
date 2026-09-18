/**
 * IO Punto de Venta para Imprenta - integración con la app del POS de YITH.
 *
 * El punto de venta de YITH es una aplicación React ya compilada, así que todo
 * lo que hacemos acá pasa por los filtros públicos que expone en wp.hooks. Solo
 * dos funciones (el mínimo de letras del buscador y el cobro de seña) necesitan
 * hablar con la instancia de React; ambas se degradan solas si YITH cambia su
 * estructura interna.
 */
( function ( window, document ) {
	'use strict';

	var config = window.ioPosConfig || {};
	var hooks = window.wp && window.wp.hooks;

	if ( ! hooks ) {
		return;
	}

	var NAMESPACE = 'io-pos';
	var BALANCE_MARKER = 'IOPOSBAL';
	var STORAGE_KEY = 'ioPosJobData';

	var jobConfig = config.job || {};
	var searchConfig = config.search || {};
	var depositConfig = config.deposit || {};
	var productionConfig = config.production || {};
	var i18n = config.i18n || {};

	var jobFields = jobConfig.fields || [];
	var jobEnabled = !! jobConfig.enabled && jobFields.length > 0;

	var elements = {};
	var currentCartIndex = null;

	/* ------------------------------------------------------------------ *
	 * Utilidades
	 * ------------------------------------------------------------------ */

	function sprintf( template, value ) {
		return String( template ).replace( /%[sd]/, value );
	}

	function readStorage() {
		try {
			var raw = window.localStorage.getItem( STORAGE_KEY );

			return raw ? JSON.parse( raw ) : {};
		} catch ( error ) {
			return {};
		}
	}

	function writeStorage( data ) {
		try {
			window.localStorage.setItem( STORAGE_KEY, JSON.stringify( data ) );
		} catch ( error ) {
			/* El almacenamiento puede estar bloqueado; seguimos sin persistir. */
		}
	}

	function getCartIndex() {
		try {
			var raw = window.localStorage.getItem( 'yithPOS_currentCart' );

			return null === raw ? 0 : JSON.parse( raw );
		} catch ( error ) {
			return 0;
		}
	}

	function emptyJob() {
		return {
			values: {},
			productionStatus: productionConfig.default || '',
			deposit: '',
			balanceFeeKey: null
		};
	}

	function getJob() {
		var all = readStorage();
		var key = String( getCartIndex() );
		var job = all[ key ];

		if ( ! job || 'object' !== typeof job ) {
			return emptyJob();
		}

		job.values = job.values || {};

		return job;
	}

	function saveJob( job ) {
		var all = readStorage();

		all[ String( getCartIndex() ) ] = job;

		writeStorage( all );
		renderChip();
	}

	function clearJob() {
		var all = readStorage();

		delete all[ String( getCartIndex() ) ];

		writeStorage( all );
		renderChip();
	}

	function hasJobData( job ) {
		if ( job.deposit ) {
			return true;
		}

		for ( var key in job.values ) {
			if ( Object.prototype.hasOwnProperty.call( job.values, key ) && '' !== String( job.values[ key ] ).trim() ) {
				return true;
			}
		}

		return false;
	}

	function getDeliveryDate( job ) {
		return ( job.values && job.values.delivery_date ) ? String( job.values.delivery_date ) : '';
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

	function daysUntil( iso ) {
		if ( ! iso ) {
			return null;
		}

		var parts = iso.split( '-' );

		if ( 3 !== parts.length ) {
			return null;
		}

		var target = new Date( parseInt( parts[ 0 ], 10 ), parseInt( parts[ 1 ], 10 ) - 1, parseInt( parts[ 2 ], 10 ) );
		var now = new Date();

		target.setHours( 0, 0, 0, 0 );
		now.setHours( 0, 0, 0, 0 );

		return Math.round( ( target - now ) / 86400000 );
	}

	function formatDate( iso ) {
		var days = daysUntil( iso );

		if ( null === days ) {
			return '';
		}

		var parts = iso.split( '-' );

		return parts[ 2 ] + '/' + parts[ 1 ] + '/' + parts[ 0 ];
	}

	function deliveryHint( iso ) {
		var days = daysUntil( iso );

		if ( null === days ) {
			return '';
		}

		if ( days < 0 ) {
			return i18n.overdue || '';
		}

		if ( 0 === days ) {
			return i18n.today || '';
		}

		if ( 1 === days ) {
			return i18n.tomorrow || '';
		}

		return sprintf( i18n.inDays || '', days );
	}

	function deliveryState( iso ) {
		var days = daysUntil( iso );

		if ( null === days ) {
			return 'none';
		}

		if ( days < 0 ) {
			return 'overdue';
		}

		if ( 0 === days ) {
			return 'today';
		}

		return days <= 2 ? 'soon' : 'scheduled';
	}

	function parseAmount( value ) {
		var amount = parseFloat( String( value ).replace( ',', '.' ) );

		return isNaN( amount ) ? 0 : amount;
	}

	function formatPrice( amount ) {
		var settings = window.yithPosSettings && window.yithPosSettings.wc ? window.yithPosSettings.wc.currency : null;

		if ( ! settings ) {
			return String( amount );
		}

		var fixed = Number( amount ).toFixed( settings.precision );
		var parts = fixed.split( '.' );

		parts[ 0 ] = parts[ 0 ].replace( /\B(?=(\d{3})+(?!\d))/g, settings.thousand_separator );

		return settings.symbol + ' ' + parts.join( settings.decimal_separator );
	}

	function createElement( tag, attributes, children ) {
		var node = document.createElement( tag );

		attributes = attributes || {};

		for ( var key in attributes ) {
			if ( ! Object.prototype.hasOwnProperty.call( attributes, key ) ) {
				continue;
			}

			if ( 'text' === key ) {
				node.textContent = attributes[ key ];
			} else if ( 'html' === key ) {
				node.innerHTML = attributes[ key ];
			} else if ( 'class' === key ) {
				node.className = attributes[ key ];
			} else {
				node.setAttribute( key, attributes[ key ] );
			}
		}

		( children || [] ).forEach( function ( child ) {
			if ( child ) {
				node.appendChild( child );
			}
		} );

		return node;
	}

	/* ------------------------------------------------------------------ *
	 * Puente con la instancia de React de YITH POS
	 * ------------------------------------------------------------------ */

	var bridge = {
		fiberOf: function ( node ) {
			if ( ! node ) {
				return null;
			}

			for ( var key in node ) {
				if ( 0 === key.indexOf( '__reactFiber$' ) || 0 === key.indexOf( '__reactInternalInstance$' ) ) {
					return node[ key ];
				}
			}

			return null;
		},

		/**
		 * Busca, subiendo por el árbol de React, la instancia que tenga una
		 * propiedad determinada.
		 *
		 * @param {string} selector Selector del nodo donde empezar.
		 * @param {string} property Propiedad que identifica a la instancia.
		 * @return {Object|null} La instancia, o null si no está disponible.
		 */
		find: function ( selector, property ) {
			try {
				var node = document.querySelector( selector );
				var fiber = this.fiberOf( node );
				var guard = 0;

				while ( fiber && guard < 80 ) {
					var instance = fiber.stateNode;

					if ( instance && 'object' === typeof instance && ! ( instance instanceof window.Node ) && property in instance ) {
						return instance;
					}

					fiber = fiber.return;
					guard++;
				}
			} catch ( error ) {
				return null;
			}

			return null;
		},

		app: function () {
			return this.find( '.yith-pos-wrap', 'cartManager' );
		},

		cartManager: function () {
			var app = this.app();

			return app ? app.cartManager : null;
		}
	};

	/* ------------------------------------------------------------------ *
	 * Buscador
	 * ------------------------------------------------------------------ */

	if ( false !== searchConfig.enabled ) {
		// YITH bloquea el clic sobre cualquier resultado sin stock: en una
		// imprenta casi todo se produce a pedido, así que lo habilitamos.
		hooks.addFilter(
			'yith_pos_enable_modal_on_click_product_name_searchbar',
			NAMESPACE,
			function ( enabled ) {
				return searchConfig.forceSelectable ? true : enabled;
			}
		);

		hooks.addFilter(
			'yith_pos_show_stock_badge_in_search_results',
			NAMESPACE,
			function ( show ) {
				return searchConfig.showStockBadge ? true : show;
			}
		);

		hooks.addFilter(
			'yith_pos_search_results_product_name',
			NAMESPACE,
			function ( name, product ) {
				if ( searchConfig.showSku && product && product.sku ) {
					return name + ' · ' + product.sku;
				}

				return name;
			}
		);
	}

	/**
	 * Baja el mínimo de 3 letras que exige el buscador de YITH.
	 */
	function patchSearchMinChars() {
		var minChars = parseInt( searchConfig.minChars, 10 );

		if ( ! minChars || 3 === minChars ) {
			return true;
		}

		var instance = bridge.find( 'input.product-search', 'minChar' );

		if ( ! instance ) {
			return false;
		}

		instance.minChar = minChars;

		return true;
	}

	/* ------------------------------------------------------------------ *
	 * Seña y saldo pendiente
	 * ------------------------------------------------------------------ */

	function depositAvailable() {
		return !! depositConfig.enabled && !! bridge.cartManager();
	}

	function findBalanceFee( cartManager ) {
		if ( ! cartManager || 'function' !== typeof cartManager.getCart ) {
			return null;
		}

		try {
			var cart = cartManager.getCart();
			var fees = ( cart && cart.feesAndDiscounts ) || [];

			for ( var index = 0; index < fees.length; index++ ) {
				if ( BALANCE_MARKER === fees[ index ].reason ) {
					return fees[ index ];
				}
			}
		} catch ( error ) {
			return null;
		}

		return null;
	}

	/**
	 * Deja el carrito con el importe que hay que cobrar ahora.
	 *
	 * @param {number} deposit Importe de la seña. Cero para cobrar el total.
	 * @return {Object} Resultado con el total del trabajo y el saldo.
	 */
	function applyDeposit( deposit ) {
		var cartManager = bridge.cartManager();
		var app = bridge.app();
		var result = { jobTotal: 0, balance: 0, deposit: 0, applied: false };

		if ( ! cartManager || ! app ) {
			return result;
		}

		var existing = findBalanceFee( cartManager );

		if ( existing ) {
			cartManager.removeFeeOrDiscount( existing.key );
		}

		var jobTotal = parseAmount( cartManager.getCartTotal() );

		result.jobTotal = jobTotal;

		if ( deposit > 0 && deposit < jobTotal ) {
			cartManager.addFeeOrDiscount( {
				type: 'fee',
				amount: -( jobTotal - deposit ),
				percentage: false,
				reason: BALANCE_MARKER
			} );

			result.balance = jobTotal - deposit;
			result.deposit = deposit;
			result.applied = true;
		}

		app._updateCurrentCart();

		return result;
	}

	function getCartTotalWithoutBalance() {
		var cartManager = bridge.cartManager();

		if ( ! cartManager ) {
			return 0;
		}

		var total = parseAmount( cartManager.getCartTotal() );
		var fee = findBalanceFee( cartManager );

		return fee ? total + Math.abs( parseAmount( fee.amount ) ) : total;
	}

	/* ------------------------------------------------------------------ *
	 * Panel de datos del trabajo
	 * ------------------------------------------------------------------ */

	function buildField( field, value ) {
		var inputId = 'io-pos-field-' + field.key;
		var input;

		if ( 'textarea' === field.type ) {
			input = createElement( 'textarea', { id: inputId, rows: '3' } );
			input.value = value || '';
		} else if ( 'select' === field.type ) {
			input = createElement( 'select', { id: inputId } );
			input.appendChild( createElement( 'option', { value: '', text: i18n.selectOption || '' } ) );

			( field.options || [] ).forEach( function ( option ) {
				var node = createElement( 'option', { value: option, text: option } );

				if ( option === value ) {
					node.setAttribute( 'selected', 'selected' );
				}

				input.appendChild( node );
			} );

			input.value = value || '';
		} else {
			input = createElement( 'input', {
				id: inputId,
				type: 'number' === field.type ? 'number' : ( 'date' === field.type ? 'date' : 'text' )
			} );

			if ( 'number' === field.type ) {
				input.setAttribute( 'step', 'any' );
			}

			input.value = value || '';
		}

		input.className = 'io-pos-modal__input';
		input.setAttribute( 'data-field', field.key );

		var label = createElement( 'label', {
			class: 'io-pos-modal__label',
			for: inputId,
			text: field.label + ( field.required ? ' *' : '' )
		} );

		var wrapper = createElement( 'div', { class: 'io-pos-modal__field io-pos-modal__field--' + field.type }, [ label, input ] );

		if ( 'delivery_date' === field.key ) {
			wrapper.appendChild( buildDateShortcuts( input ) );
		}

		return wrapper;
	}

	function buildDateShortcuts( input ) {
		var shortcuts = [
			{ label: i18n.today || 'Hoy', days: 0 },
			{ label: i18n.tomorrow || '+1', days: 1 },
			{ label: '+3', days: 3 },
			{ label: '+7', days: 7 }
		];

		var row = createElement( 'div', { class: 'io-pos-modal__shortcuts' } );

		shortcuts.forEach( function ( shortcut ) {
			var button = createElement( 'button', {
				type: 'button',
				class: 'io-pos-modal__shortcut',
				text: shortcut.label
			} );

			button.addEventListener( 'click', function () {
				input.value = addDays( shortcut.days );
			} );

			row.appendChild( button );
		} );

		return row;
	}

	function closeModal() {
		if ( elements.modal && elements.modal.parentNode ) {
			elements.modal.parentNode.removeChild( elements.modal );
		}

		elements.modal = null;
	}

	function openModal() {
		if ( elements.modal ) {
			return;
		}

		var job = getJob();
		var body = createElement( 'div', { class: 'io-pos-modal__body' } );

		jobFields.forEach( function ( field ) {
			var value = job.values[ field.key ];

			if ( 'delivery_date' === field.key && ! value && jobConfig.defaultDays >= 0 ) {
				value = addDays( jobConfig.defaultDays );
			}

			body.appendChild( buildField( field, value ) );
		} );

		if ( productionConfig.enabled && ( productionConfig.statuses || [] ).length ) {
			var statusSelect = createElement( 'select', { id: 'io-pos-production-status', class: 'io-pos-modal__input' } );

			productionConfig.statuses.forEach( function ( status ) {
				var option = createElement( 'option', { value: status.key, text: status.label } );

				if ( status.key === job.productionStatus ) {
					option.setAttribute( 'selected', 'selected' );
				}

				statusSelect.appendChild( option );
			} );

			statusSelect.value = job.productionStatus || productionConfig.default || '';

			body.appendChild(
				createElement( 'div', { class: 'io-pos-modal__field' }, [
					createElement( 'label', {
						class: 'io-pos-modal__label',
						for: 'io-pos-production-status',
						text: i18n.productionState || ''
					} ),
					statusSelect
				] )
			);

			elements.statusSelect = statusSelect;
		} else {
			elements.statusSelect = null;
		}

		elements.depositInput = null;

		if ( depositConfig.enabled ) {
			var jobTotal = getCartTotalWithoutBalance();

			if ( depositAvailable() ) {
				var depositInput = createElement( 'input', {
					id: 'io-pos-deposit',
					type: 'number',
					step: 'any',
					min: '0',
					class: 'io-pos-modal__input'
				} );

				depositInput.value = job.deposit || '';

				body.appendChild(
					createElement( 'div', { class: 'io-pos-modal__field io-pos-modal__field--deposit' }, [
						createElement( 'label', {
							class: 'io-pos-modal__label',
							for: 'io-pos-deposit',
							text: ( i18n.deposit || '' ) + ' — ' + ( i18n.jobTotal || '' ) + ': ' + formatPrice( jobTotal )
						} ),
						depositInput,
						createElement( 'small', { class: 'io-pos-modal__help', text: i18n.depositHelp || '' } )
					] )
				);

				elements.depositInput = depositInput;
			} else {
				body.appendChild(
					createElement( 'div', { class: 'io-pos-modal__field' }, [
						createElement( 'small', { class: 'io-pos-modal__help', text: i18n.unavailable || '' } )
					] )
				);
			}
		}

		var error = createElement( 'div', { class: 'io-pos-modal__error' } );

		body.appendChild( error );

		var saveButton = createElement( 'button', { type: 'button', class: 'io-pos-modal__button io-pos-modal__button--primary', text: i18n.save || '' } );
		var cancelButton = createElement( 'button', { type: 'button', class: 'io-pos-modal__button', text: i18n.cancel || '' } );
		var clearButton = createElement( 'button', { type: 'button', class: 'io-pos-modal__button io-pos-modal__button--link', text: i18n.clear || '' } );

		var modal = createElement( 'div', { class: 'io-pos-modal' }, [
			createElement( 'div', { class: 'io-pos-modal__overlay' } ),
			createElement( 'div', { class: 'io-pos-modal__content' }, [
				createElement( 'h2', { class: 'io-pos-modal__title', text: i18n.jobTitle || '' } ),
				body,
				createElement( 'div', { class: 'io-pos-modal__footer' }, [ clearButton, cancelButton, saveButton ] )
			] )
		] );

		modal.querySelector( '.io-pos-modal__overlay' ).addEventListener( 'click', closeModal );
		cancelButton.addEventListener( 'click', closeModal );

		clearButton.addEventListener( 'click', function () {
			if ( depositAvailable() ) {
				applyDeposit( 0 );
			}

			clearJob();
			closeModal();
		} );

		saveButton.addEventListener( 'click', function () {
			var message = saveModal( modal, job );

			if ( message ) {
				error.textContent = message;

				return;
			}

			closeModal();
		} );

		document.body.appendChild( modal );

		elements.modal = modal;

		var firstInput = modal.querySelector( '.io-pos-modal__input' );

		if ( firstInput ) {
			firstInput.focus();
		}
	}

	/**
	 * Valida y guarda el contenido del panel.
	 *
	 * @param {HTMLElement} modal El panel.
	 * @param {Object}      job   Datos actuales.
	 * @return {string} Mensaje de error, o cadena vacía si se guardó bien.
	 */
	function saveModal( modal, job ) {
		var values = {};
		var missing = false;

		jobFields.forEach( function ( field ) {
			var input = modal.querySelector( '[data-field="' + field.key + '"]' );

			if ( ! input ) {
				return;
			}

			var value = String( input.value || '' ).trim();

			if ( field.required && ! value ) {
				missing = true;

				input.classList.add( 'io-pos-modal__input--error' );
			} else {
				input.classList.remove( 'io-pos-modal__input--error' );
			}

			if ( value ) {
				values[ field.key ] = value;
			}
		} );

		if ( missing ) {
			return i18n.requiredError || '';
		}

		job.values = values;

		if ( elements.statusSelect ) {
			job.productionStatus = elements.statusSelect.value;
		}

		if ( elements.depositInput && depositAvailable() ) {
			var deposit = parseAmount( elements.depositInput.value );
			var jobTotal = getCartTotalWithoutBalance();

			if ( deposit > jobTotal ) {
				return i18n.depositTooHigh || '';
			}

			var minPercent = parseFloat( depositConfig.minPercent ) || 0;

			if ( deposit > 0 && minPercent > 0 && deposit < ( jobTotal * minPercent / 100 ) ) {
				return i18n.depositTooLow || '';
			}

			var applied = applyDeposit( deposit );

			job.deposit = applied.applied ? deposit : '';
		}

		saveJob( job );

		return '';
	}

	/* ------------------------------------------------------------------ *
	 * Indicador flotante
	 * ------------------------------------------------------------------ */

	function renderChip() {
		if ( ! jobEnabled ) {
			return;
		}

		if ( ! elements.chip ) {
			elements.chip = createElement( 'button', { type: 'button', class: 'io-pos-chip' } );
			elements.chip.addEventListener( 'click', openModal );

			document.body.appendChild( elements.chip );
		}

		var job = getJob();
		var date = getDeliveryDate( job );
		var state = deliveryState( date );
		var label = date ? ( i18n.deliveryOn || '' ) + ': ' + formatDate( date ) : ( i18n.noDelivery || '' );
		var hint = deliveryHint( date );

		if ( hint ) {
			label += ' · ' + hint;
		}

		if ( job.deposit ) {
			label += ' · ' + ( i18n.depositApplied || '' ) + ' ' + formatPrice( parseAmount( job.deposit ) );
		}

		elements.chip.textContent = label;
		elements.chip.className = 'io-pos-chip io-pos-chip--' + ( date ? state : 'empty' );
	}

	/* ------------------------------------------------------------------ *
	 * Menú del POS
	 * ------------------------------------------------------------------ */

	if ( jobEnabled ) {
		hooks.addFilter( 'yith_pos_header_menu_items', NAMESPACE, function ( items ) {
			return ( items || [] ).concat( [ {
				title: i18n.menuTitle || '',
				icon: 'shipping',
				internalLink: false,
				href: '',
				action: 'ioPosJob',
				class: 'io-pos-menu-job'
			} ] );
		} );

		document.addEventListener( 'click', function ( event ) {
			var target = event.target;

			while ( target && target !== document.body ) {
				if ( target.classList && target.classList.contains( 'io-pos-menu-job' ) ) {
					openModal();

					return;
				}

				target = target.parentNode;
			}
		}, false );
	}

	/* ------------------------------------------------------------------ *
	 * Pedido generado
	 * ------------------------------------------------------------------ */

	hooks.addFilter( 'yith_pos_cart_generated_order', NAMESPACE, function ( order ) {
		var job = getJob();

		order.meta_data = order.meta_data || [];

		if ( jobEnabled && hasJobData( job ) ) {
			jobFields.forEach( function ( field ) {
				var value = job.values[ field.key ];

				if ( value && '' !== String( value ).trim() ) {
					order.meta_data.push( { key: field.metaKey, value: String( value ) } );
				}
			} );

			if ( productionConfig.enabled && productionConfig.metaKey ) {
				order.meta_data.push( {
					key: productionConfig.metaKey,
					value: job.productionStatus || productionConfig.default || ''
				} );
			}
		}

		// Renombramos la línea negativa que representa el saldo pendiente y la
		// marcamos para poder encontrarla después desde WordPress.
		( order.fee_lines || [] ).forEach( function ( line ) {
			if ( ! line || ! line.name || line.name.indexOf( BALANCE_MARKER ) < 0 ) {
				return;
			}

			line.name = depositConfig.label || line.name;
			line.meta_data = ( line.meta_data || [] ).concat( [ { key: '_io_pos_balance_line', value: '1' } ] );

			var balance = Math.abs( parseAmount( line.total ) );

			// WordPress vuelve a calcular estos importes desde el pedido real;
			// los mandamos igual para tener el dato aunque falle el cálculo.
			order.meta_data.push( { key: depositConfig.balanceMeta, value: String( balance ) } );

			if ( job.deposit ) {
				order.meta_data.push( { key: depositConfig.depositMeta, value: String( parseAmount( job.deposit ) ) } );
				order.meta_data.push( {
					key: depositConfig.jobTotalMeta,
					value: String( parseAmount( job.deposit ) + balance )
				} );
			}
		} );

		return order;
	} );

	hooks.addAction( 'yith_pos_order_processed_after_showing_details', NAMESPACE, function () {
		clearJob();
	} );

	/* ------------------------------------------------------------------ *
	 * Ticket
	 * ------------------------------------------------------------------ */

	hooks.addFilter( 'yith_pos_receipt_show_order_item_note', NAMESPACE, function () {
		return true;
	} );

	if ( jobConfig.showOnReceipt ) {
		hooks.addFilter( 'yith_pos_receipt_order_data_elements', NAMESPACE, function ( elementsList, order ) {
			var data = order && order.io_pos_job;

			if ( ! data ) {
				return elementsList;
			}

			var extra = [];

			( data.details || [] ).forEach( function ( detail ) {
				if ( ! detail.receipt ) {
					return;
				}

				extra.push( {
					id: 'io-pos-' + detail.key,
					show: true,
					value: detail.formatted || detail.value,
					label: detail.label + ':'
				} );
			} );

			if ( data.balance_due > 0 ) {
				extra.push( {
					id: 'io-pos-job-total',
					show: true,
					value: formatPrice( data.job_total ),
					label: ( i18n.jobTotal || '' ) + ':'
				} );

				extra.push( {
					id: 'io-pos-deposit',
					show: true,
					value: formatPrice( data.deposit ),
					label: ( i18n.depositApplied || '' ) + ':'
				} );

				extra.push( {
					id: 'io-pos-balance',
					show: true,
					value: formatPrice( data.balance_due ),
					label: ( i18n.balance || '' ) + ':'
				} );
			}

			return ( elementsList || [] ).concat( extra );
		} );
	}

	/* ------------------------------------------------------------------ *
	 * Validaciones antes de cobrar
	 * ------------------------------------------------------------------ */

	function isReadyToPay() {
		if ( ! jobEnabled || ! jobConfig.required ) {
			return true;
		}

		return '' !== getDeliveryDate( getJob() );
	}

	/**
	 * Envuelve el botón de cobro para pedir los datos del trabajo primero y
	 * para recalcular la seña con el total que tiene el carrito en ese momento.
	 *
	 * @return {boolean} Si se pudo envolver.
	 */
	function patchPayModal() {
		var app = bridge.app();

		if ( ! app || 'function' !== typeof app.payModal || app.__ioPosPatched ) {
			return !! ( app && app.__ioPosPatched );
		}

		var original = app.payModal;

		app.payModal = function () {
			if ( ! isReadyToPay() ) {
				openModal();

				return;
			}

			var job = getJob();

			if ( job.deposit && depositAvailable() ) {
				applyDeposit( parseAmount( job.deposit ) );
			}

			return original.apply( app, arguments );
		};

		app.__ioPosPatched = true;

		return true;
	}

	// Red de seguridad: si no pudimos envolver el botón, frenamos el pedido
	// antes de que llegue al servidor.
	if ( window.wp && window.wp.apiFetch && jobEnabled && jobConfig.required ) {
		window.wp.apiFetch.use( function ( options, next ) {
			var path = options.path || '';

			if ( 'POST' === ( options.method || '' ).toUpperCase() && path.indexOf( '/wc/v3/orders' ) >= 0 && ! isReadyToPay() ) {
				openModal();

				return Promise.reject( { message: i18n.requiredToPay || '' } );
			}

			return next( options );
		} );
	}

	/* ------------------------------------------------------------------ *
	 * Arranque
	 * ------------------------------------------------------------------ */

	/**
	 * Tarea de mantenimiento: la app de YITH desmonta y vuelve a montar sus
	 * componentes al navegar, así que hay que volver a aplicar los parches.
	 */
	function tick() {
		var index = getCartIndex();

		if ( index !== currentCartIndex ) {
			currentCartIndex = index;

			renderChip();
		}

		patchSearchMinChars();
		patchPayModal();
	}

	function boot() {
		if ( ! document.querySelector( '.yith-pos-wrap' ) ) {
			return false;
		}

		patchSearchMinChars();
		patchPayModal();
		renderChip();

		return true;
	}

	function start() {
		var attempts = 0;
		var timer = window.setInterval( function () {
			attempts++;

			if ( boot() || attempts > 40 ) {
				window.clearInterval( timer );

				if ( attempts <= 40 ) {
					window.setInterval( tick, 800 );
				}
			}
		}, 250 );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )( window, document );
