<?php
/**
 * Settings screen.
 *
 * @package IO\POS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class IO_POS_Admin_Settings
 */
class IO_POS_Admin_Settings {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 30 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'option_page_capability_io_pos_settings_group', array( $this, 'get_capability' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( IO_POS_FILE ), array( $this, 'add_action_link' ) );
	}

	/**
	 * Crea el menú del plugin.
	 *
	 * La pantalla de producción la sigue manejando el Panel Taller, así que acá
	 * solo van los ajustes y el acceso directo al mostrador.
	 */
	public function add_menu() {
		$capability = apply_filters( 'io_pos_settings_capability', 'manage_woocommerce' );

		add_menu_page(
			__( 'Mostrador', 'io-punto-venta' ),
			__( 'Mostrador', 'io-punto-venta' ),
			$capability,
			'io-pos-settings',
			array( $this, 'render' ),
			'dashicons-store',
			56
		);

		add_submenu_page(
			'io-pos-settings',
			__( 'Ajustes del mostrador', 'io-punto-venta' ),
			__( 'Ajustes', 'io-punto-venta' ),
			$capability,
			'io-pos-settings',
			array( $this, 'render' )
		);

		$terminal_url = io_pos_get_terminal_url();

		if ( $terminal_url && current_user_can( 'io_pos_use' ) ) {
			add_submenu_page(
				'io-pos-settings',
				__( 'Abrir el mostrador', 'io-punto-venta' ),
				__( 'Abrir el mostrador', 'io-punto-venta' ),
				'io_pos_use',
				$terminal_url
			);
		}
	}

	/**
	 * Add a settings link to the plugins list.
	 *
	 * @param array $links Existing links.
	 *
	 * @return array
	 */
	public function add_action_link( $links ) {
		$url = add_query_arg( 'page', 'io-pos-settings', admin_url( 'admin.php' ) );

		array_unshift( $links, sprintf( '<a href="%1$s">%2$s</a>', esc_url( $url ), esc_html__( 'Ajustes', 'io-punto-venta' ) ) );

		return $links;
	}

	/**
	 * Capability required to save the settings.
	 *
	 * @return string
	 */
	public function get_capability() {
		return apply_filters( 'io_pos_settings_capability', 'manage_woocommerce' );
	}

	/**
	 * Register the option.
	 */
	public function register_settings() {
		register_setting(
			'io_pos_settings_group',
			IO_POS_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'IO_POS_Settings', 'sanitize' ),
				'default'           => IO_POS_Settings::defaults(),
			)
		);
	}

	/**
	 * Definition of every field shown on the settings screen.
	 *
	 * @return array
	 */
	protected function get_sections() {
		$order_statuses = array();

		foreach ( wc_get_order_statuses() as $key => $label ) {
			$order_statuses[ str_replace( 'wc-', '', $key ) ] = $label;
		}

		return array(
			'search'     => array(
				'title'  => __( 'Buscador de productos', 'io-punto-venta' ),
				'intro'  => __( 'Corrige las limitaciones del buscador de YITH POS: pocos resultados, búsqueda solo por título exacto y productos que no se pueden seleccionar.', 'io-punto-venta' ),
				'fields' => array(
					'search_enabled'             => array(
						'type'  => 'checkbox',
						'label' => __( 'Activar el buscador mejorado', 'io-punto-venta' ),
						'desc'  => __( 'Busca por varias palabras sueltas, por SKU y por descripción corta.', 'io-punto-venta' ),
					),
					'search_max_results'         => array(
						'type'  => 'number',
						'label' => __( 'Cantidad de resultados', 'io-punto-venta' ),
						'desc'  => __( 'Cuántos productos muestra la lista del buscador (máximo 100). YITH POS trae 10 de fábrica.', 'io-punto-venta' ),
						'min'   => 1,
						'max'   => 100,
					),
					'search_min_chars'           => array(
						'type'  => 'number',
						'label' => __( 'Mínimo de letras para buscar', 'io-punto-venta' ),
						'desc'  => __( 'YITH POS exige 3 letras. Con 2 se encuentran códigos y medidas cortas.', 'io-punto-venta' ),
						'min'   => 1,
						'max'   => 10,
					),
					'search_include_sku'         => array(
						'type'  => 'checkbox',
						'label' => __( 'Buscar también por SKU / código de barras', 'io-punto-venta' ),
					),
					'search_include_variations'  => array(
						'type'  => 'checkbox',
						'label' => __( 'Incluir variaciones por SKU', 'io-punto-venta' ),
						'desc'  => __( 'Escribiendo el SKU de una variación se agrega directamente esa variación.', 'io-punto-venta' ),
					),
					'search_include_description' => array(
						'type'  => 'checkbox',
						'label' => __( 'Buscar también en la descripción corta', 'io-punto-venta' ),
					),
					'search_force_selectable'    => array(
						'type'  => 'checkbox',
						'label' => __( 'Permitir seleccionar todos los productos', 'io-punto-venta' ),
						'desc'  => __( 'Muestra y deja agregar productos sin precio o sin stock. Es lo que hace que el buscador deje de "no dejar seleccionar" productos. El precio se puede editar en el carrito.', 'io-punto-venta' ),
					),
					'search_show_sku'            => array(
						'type'  => 'checkbox',
						'label' => __( 'Mostrar el SKU en los resultados', 'io-punto-venta' ),
					),
					'search_show_stock_badge'    => array(
						'type'  => 'checkbox',
						'label' => __( 'Mostrar el stock en los resultados', 'io-punto-venta' ),
					),
				),
			),
			'job'        => array(
				'title'  => __( 'Datos del trabajo', 'io-punto-venta' ),
				'intro'  => __( 'Agrega al punto de venta la fecha de entrega y los datos propios de un trabajo de imprenta.', 'io-punto-venta' ),
				'fields' => array(
					'job_enabled'                 => array(
						'type'  => 'checkbox',
						'label' => __( 'Activar los datos del trabajo en el POS', 'io-punto-venta' ),
					),
					'job_delivery_required'       => array(
						'type'  => 'checkbox',
						'label' => __( 'Exigir la fecha de entrega antes de cobrar', 'io-punto-venta' ),
					),
					'job_time_slots'             => array(
						'type'  => 'textarea',
						'label' => __( 'Horarios de entrega', 'io-punto-venta' ),
						'desc'  => __( 'Uno por línea. Dejalo vacío para no usar horarios.', 'io-punto-venta' ),
					),
					'job_delivery_methods'        => array(
						'type'  => 'textarea',
						'label' => __( 'Formas de entrega', 'io-punto-venta' ),
						'desc'  => __( 'Una por línea.', 'io-punto-venta' ),
					),
					'job_priorities'              => array(
						'type'  => 'textarea',
						'label' => __( 'Prioridades', 'io-punto-venta' ),
						'desc'  => __( 'Una por línea.', 'io-punto-venta' ),
					),
					'job_custom_fields'           => array(
						'type'  => 'textarea',
						'label' => __( 'Campos propios del trabajo', 'io-punto-venta' ),
						'desc'  => __( 'Un campo por línea con el formato <code>clave|Etiqueta|tipo|opciones|marcas</code>. Tipos: text, textarea, number, date, select. Las opciones se separan con comas (solo para select). Marcas posibles: <code>obligatorio</code> y <code>ticket</code> (se imprime en el comprobante).', 'io-punto-venta' ),
						'rows'  => 6,
					),
					'job_customer_note'           => array(
						'type'  => 'checkbox',
						'label' => __( 'Pedir observaciones del cliente', 'io-punto-venta' ),
						'desc'  => __( 'Se guardan como nota del cliente en el pedido, igual que cuando compran por la web.', 'io-punto-venta' ),
					),
					'job_show_on_receipt'         => array(
						'type'  => 'checkbox',
						'label' => __( 'Imprimir los datos del trabajo en el ticket', 'io-punto-venta' ),
					),
					'job_show_on_customer_emails' => array(
						'type'  => 'checkbox',
						'label' => __( 'Mostrar los datos del trabajo al cliente', 'io-punto-venta' ),
						'desc'  => __( 'En los emails de WooCommerce y en el detalle del pedido.', 'io-punto-venta' ),
					),
				),
			),
			'delivery'   => array(
				'title'  => __( 'Fecha de entrega', 'io-punto-venta' ),
				'intro'  => __( 'Reemplaza al plugin de fechas de entrega. Cada producto puede declarar sus días de producción en su ficha (pestaña Inventario); si no lo hace, se usa el valor por defecto. Los días se cuentan hábiles.', 'io-punto-venta' ),
				'fields' => array(
					'delivery_enabled'           => array(
						'type'  => 'checkbox',
						'label' => __( 'Activar el cálculo de fechas', 'io-punto-venta' ),
					),
					'delivery_default_days'      => array(
						'type'  => 'number',
						'label' => __( 'Días de producción por defecto', 'io-punto-venta' ),
						'desc'  => __( 'Para los productos que no declaran los suyos.', 'io-punto-venta' ),
						'min'   => 0,
						'max'   => 365,
					),
					'delivery_workdays'          => array(
						'type'  => 'text',
						'label' => __( 'Días que trabaja el taller', 'io-punto-venta' ),
						'desc'  => __( 'Separados por coma: 1 es lunes y 7 domingo. Por ejemplo <code>1,2,3,4,5</code> para lunes a viernes.', 'io-punto-venta' ),
					),
					'delivery_holidays'          => array(
						'type'  => 'textarea',
						'label' => __( 'Feriados', 'io-punto-venta' ),
						'desc'  => __( 'Una fecha por línea, en formato dd/mm/aaaa. Esos días no se cuentan ni se ofrecen.', 'io-punto-venta' ),
						'rows'  => 6,
					),
					'delivery_cutoff'            => array(
						'type'  => 'text',
						'label' => __( 'Hora de corte', 'io-punto-venta' ),
						'desc'  => __( 'En formato 24 horas, por ejemplo <code>14:00</code>. Después de esa hora el trabajo entra al taller al día siguiente. Dejalo vacío para no usar corte.', 'io-punto-venta' ),
					),
					'delivery_max_options'       => array(
						'type'  => 'number',
						'label' => __( 'Fechas para elegir', 'io-punto-venta' ),
						'desc'  => __( 'Cuántas fechas se ofrecen en la web y en el mostrador.', 'io-punto-venta' ),
						'min'   => 1,
						'max'   => 60,
					),
					'delivery_checkout_enabled'  => array(
						'type'  => 'checkbox',
						'label' => __( 'Pedir la fecha al comprar por la web', 'io-punto-venta' ),
						'desc'  => __( 'Muestra el selector de fechas en el checkout, antes de pagar.', 'io-punto-venta' ),
					),
					'delivery_checkout_required' => array(
						'type'  => 'checkbox',
						'label' => __( 'La fecha es obligatoria en la web', 'io-punto-venta' ),
					),
				),
			),
			'production' => array(
				'title'  => __( 'Producción', 'io-punto-venta' ),
				'intro'  => __( 'La pantalla de producción es el Panel Taller: el mostrador escribe en sus mismas claves (fase y fecha de entrega), no crea otras. Estas fases solo se usan como respaldo si el Panel Taller no está activo.', 'io-punto-venta' ),
				'fields' => array(
					'production_enabled'                => array(
						'type'  => 'checkbox',
						'label' => __( 'Activar los estados de producción', 'io-punto-venta' ),
					),
					'production_statuses'               => array(
						'type'  => 'textarea',
						'label' => __( 'Estados', 'io-punto-venta' ),
						'desc'  => __( 'Uno por línea con el formato <code>clave|Etiqueta</code>, en el orden del flujo de trabajo.', 'io-punto-venta' ),
						'rows'  => 6,
					),
					'production_default'                => array(
						'type'  => 'text',
						'label' => __( 'Estado inicial', 'io-punto-venta' ),
						'desc'  => __( 'La clave del estado con el que nace un trabajo nuevo.', 'io-punto-venta' ),
					),
					'production_done'                   => array(
						'type'  => 'text',
						'label' => __( 'Estado final', 'io-punto-venta' ),
						'desc'  => __( 'La clave del estado que marca el trabajo como entregado.', 'io-punto-venta' ),
					),
					'production_order_status'           => array(
						'type'    => 'select',
						'label'   => __( 'Estado de WooCommerce para los trabajos', 'io-punto-venta' ),
						'desc'    => __( 'YITH POS marca las ventas como completadas. Un trabajo que todavía hay que producir conviene dejarlo en procesando.', 'io-punto-venta' ),
						'options' => $order_statuses,
					),
				),
			),
			'terminal'   => array(
				'title'  => __( 'Mostrador', 'io-punto-venta' ),
				'intro'  => __( 'La pantalla de venta. Se abre en la página que elijas y solo entran los usuarios con el permiso «Usar el mostrador».', 'io-punto-venta' ),
				'fields' => array(
					'terminal_enabled'            => array(
						'type'  => 'checkbox',
						'label' => __( 'Activar el mostrador', 'io-punto-venta' ),
					),
					'terminal_page_id'            => array(
						'type'  => 'page',
						'label' => __( 'Página del mostrador', 'io-punto-venta' ),
						'desc'  => __( 'La página donde se dibuja la pantalla de venta. Se crea sola al activar el plugin.', 'io-punto-venta' ),
					),
					'terminal_title'              => array(
						'type'  => 'text',
						'label' => __( 'Nombre que se ve arriba', 'io-punto-venta' ),
					),
					'terminal_products_per_page'  => array(
						'type'  => 'number',
						'label' => __( 'Productos por pantalla', 'io-punto-venta' ),
						'min'   => 4,
						'max'   => 100,
					),
					'terminal_show_images'        => array(
						'type'  => 'checkbox',
						'label' => __( 'Mostrar las fotos de los productos', 'io-punto-venta' ),
					),
					'terminal_allow_custom_items' => array(
						'type'  => 'checkbox',
						'label' => __( 'Permitir trabajos a medida', 'io-punto-venta' ),
						'desc'  => __( 'Una línea con descripción y precio escritos a mano, sin producto cargado.', 'io-punto-venta' ),
					),
					'terminal_customer_label'     => array(
						'type'  => 'text',
						'label' => __( 'Texto cuando no hay cliente', 'io-punto-venta' ),
					),
				),
			),
			'payments'   => array(
				'title'  => __( 'Cobros', 'io-punto-venta' ),
				'intro'  => __( 'El pedido se emite siempre por el total del trabajo. Lo que se cobra en el momento se guarda aparte, así se ve lo vendido y lo cobrado por separado.', 'io-punto-venta' ),
				'fields' => array(
					'payment_methods'        => array(
						'type'  => 'textarea',
						'label' => __( 'Métodos de cobro', 'io-punto-venta' ),
						'desc'  => __( 'Uno por línea con el formato <code>clave|Etiqueta</code>.', 'io-punto-venta' ),
						'rows'  => 6,
					),
					'payment_cash_method'    => array(
						'type'  => 'text',
						'label' => __( 'Clave del método en efectivo', 'io-punto-venta' ),
						'desc'  => __( 'Es el que viene elegido de entrada y el que calcula el vuelto.', 'io-punto-venta' ),
					),
					'payment_allow_partial'  => array(
						'type'  => 'checkbox',
						'label' => __( 'Permitir cobrar una seña', 'io-punto-venta' ),
						'desc'  => __( 'Deja emitir el pedido cobrando menos que el total. El resto queda como saldo pendiente.', 'io-punto-venta' ),
					),
					'payment_status_paid'    => array(
						'type'    => 'select',
						'label'   => __( 'Estado cuando se cobra todo', 'io-punto-venta' ),
						'options' => $order_statuses,
					),
					'payment_status_partial' => array(
						'type'    => 'select',
						'label'   => __( 'Estado cuando queda saldo', 'io-punto-venta' ),
						'options' => $order_statuses,
					),
					'payment_status_unpaid'  => array(
						'type'    => 'select',
						'label'   => __( 'Estado cuando no se cobra nada', 'io-punto-venta' ),
						'options' => $order_statuses,
					),
					'payment_send_email'     => array(
						'type'  => 'checkbox',
						'label' => __( 'Avisar al cliente cuando queda saldo', 'io-punto-venta' ),
						'desc'  => __( 'Manda el mismo correo de seña que el metabox «Registro de Pagos». En las ventas cobradas enteras no se manda nada, porque el cliente ya se lleva el comprobante.', 'io-punto-venta' ),
					),
					'notify_emails'          => array(
						'type'  => 'checkbox',
						'label' => __( 'Enviar los correos de WooCommerce', 'io-punto-venta' ),
						'desc'  => __( 'Activado, las ventas del mostrador mandan los mismos correos que una compra por la web.', 'io-punto-venta' ),
					),
				),
			),
			'receipt'    => array(
				'title'  => __( 'Comprobante', 'io-punto-venta' ),
				'intro'  => __( 'Lo que se imprime al cerrar la venta. Se imprime desde el navegador, así que sirve tanto para una impresora térmica como para una común.', 'io-punto-venta' ),
				'fields' => array(
					'receipt_width'         => array(
						'type'    => 'select',
						'label'   => __( 'Formato', 'io-punto-venta' ),
						'options' => array(
							'58mm' => __( 'Rollo de 58 mm', 'io-punto-venta' ),
							'80mm' => __( 'Rollo de 80 mm', 'io-punto-venta' ),
							'a4'   => __( 'Hoja A4', 'io-punto-venta' ),
						),
					),
					'receipt_store_name'    => array(
						'type'  => 'text',
						'label' => __( 'Nombre del negocio', 'io-punto-venta' ),
						'desc'  => __( 'Vacío usa el nombre del sitio.', 'io-punto-venta' ),
					),
					'receipt_store_details' => array(
						'type'  => 'textarea',
						'label' => __( 'Datos del encabezado', 'io-punto-venta' ),
						'desc'  => __( 'Dirección, teléfono, CUIT… Se imprime tal cual, respetando los saltos de línea.', 'io-punto-venta' ),
					),
					'receipt_footer'        => array(
						'type'  => 'textarea',
						'label' => __( 'Pie del comprobante', 'io-punto-venta' ),
					),
					'receipt_show_job'      => array(
						'type'  => 'checkbox',
						'label' => __( 'Imprimir los datos del trabajo', 'io-punto-venta' ),
					),
					'receipt_auto_print'    => array(
						'type'  => 'checkbox',
						'label' => __( 'Abrir la impresión al cerrar la venta', 'io-punto-venta' ),
						'desc'  => __( 'Desactivado, la venta cierra más rápido y el comprobante se imprime solo si apretás el botón.', 'io-punto-venta' ),
					),
				),
			),
		);
	}

	/**
	 * Render the settings screen.
	 */
	public function render() {
		$settings = IO_POS_Settings::all();

		echo '<div class="wrap io-pos-settings">';
		echo '<h1>' . esc_html__( 'Ajustes de la imprenta', 'io-punto-venta' ) . '</h1>';

		settings_errors();

		echo '<form method="post" action="options.php">';

		settings_fields( 'io_pos_settings_group' );

		foreach ( $this->get_sections() as $section ) {
			printf( '<h2>%s</h2>', esc_html( $section['title'] ) );

			if ( ! empty( $section['intro'] ) ) {
				printf( '<p class="description">%s</p>', esc_html( $section['intro'] ) );
			}

			echo '<table class="form-table" role="presentation"><tbody>';

			foreach ( $section['fields'] as $key => $field ) {
				$this->render_field( $key, $field, $settings[ $key ] ?? '' );
			}

			echo '</tbody></table>';
		}

		submit_button();

		echo '</form></div>';
	}

	/**
	 * Render a single field row.
	 *
	 * @param string $key   Setting key.
	 * @param array  $field Field definition.
	 * @param mixed  $value Current value.
	 */
	protected function render_field( $key, $field, $value ) {
		$name = sprintf( '%1$s[%2$s]', IO_POS_Settings::OPTION, $key );
		$id   = 'io-pos-' . str_replace( '_', '-', $key );

		echo '<tr>';
		printf( '<th scope="row"><label for="%1$s">%2$s</label></th><td>', esc_attr( $id ), esc_html( $field['label'] ) );

		switch ( $field['type'] ) {
			case 'checkbox':
				printf(
					'<label><input type="checkbox" id="%1$s" name="%2$s" value="yes"%3$s /> %4$s</label>',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( 'yes', $value, false ),
					esc_html__( 'Activado', 'io-punto-venta' )
				);
				break;
			case 'number':
				printf(
					'<input type="number" id="%1$s" name="%2$s" value="%3$s" min="%4$d" max="%5$d" class="small-text" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $value ),
					(int) ( $field['min'] ?? 0 ),
					(int) ( $field['max'] ?? 999 )
				);
				break;
			case 'textarea':
				printf(
					'<textarea id="%1$s" name="%2$s" rows="%3$d" class="large-text code">%4$s</textarea>',
					esc_attr( $id ),
					esc_attr( $name ),
					(int) ( $field['rows'] ?? 4 ),
					esc_textarea( $value )
				);
				break;
			case 'page':
				wp_dropdown_pages(
					array(
						'name'              => $name,
						'id'                => $id,
						'selected'          => absint( $value ),
						'show_option_none'  => __( '— Sin página —', 'io-punto-venta' ),
						'option_none_value' => 0,
					)
				);

				$page_url = $value ? get_permalink( absint( $value ) ) : '';

				if ( $page_url ) {
					printf(
						' <a href="%1$s" target="_blank" rel="noopener">%2$s</a>',
						esc_url( $page_url ),
						esc_html__( 'Abrir el mostrador', 'io-punto-venta' )
					);
				}
				break;
			case 'select':
				printf( '<select id="%1$s" name="%2$s">', esc_attr( $id ), esc_attr( $name ) );

				foreach ( (array) $field['options'] as $option_key => $option_label ) {
					printf(
						'<option value="%1$s"%2$s>%3$s</option>',
						esc_attr( $option_key ),
						selected( $option_key, $value, false ),
						esc_html( $option_label )
					);
				}

				echo '</select>';
				break;
			default:
				printf(
					'<input type="text" id="%1$s" name="%2$s" value="%3$s" class="regular-text" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $value )
				);
				break;
		}

		if ( ! empty( $field['desc'] ) ) {
			printf( '<p class="description">%s</p>', wp_kses_post( $field['desc'] ) );
		}

		echo '</td></tr>';
	}
}
