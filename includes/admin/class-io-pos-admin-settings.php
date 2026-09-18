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
	 * Add the settings submenu.
	 */
	public function add_menu() {
		add_submenu_page(
			'io-pos-board',
			__( 'Ajustes de la imprenta', 'io-punto-venta' ),
			__( 'Ajustes', 'io-punto-venta' ),
			apply_filters( 'io_pos_settings_capability', 'manage_woocommerce' ),
			'io-pos-settings',
			array( $this, 'render' )
		);
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
					'job_default_days'            => array(
						'type'  => 'number',
						'label' => __( 'Días de entrega por defecto', 'io-punto-venta' ),
						'desc'  => __( 'La fecha que se propone al abrir el panel del trabajo.', 'io-punto-venta' ),
						'min'   => 0,
						'max'   => 365,
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
			'production' => array(
				'title'  => __( 'Producción', 'io-punto-venta' ),
				'intro'  => __( 'Estados internos del taller. No reemplazan a los estados de WooCommerce, así los informes del punto de venta siguen siendo correctos.', 'io-punto-venta' ),
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
					'production_complete_order_on_done' => array(
						'type'  => 'checkbox',
						'label' => __( 'Completar el pedido al marcarlo como entregado', 'io-punto-venta' ),
						'desc'  => __( 'Solo si no quedó saldo pendiente.', 'io-punto-venta' ),
					),
				),
			),
			'deposit'    => array(
				'title'  => __( 'Seña y saldo', 'io-punto-venta' ),
				'intro'  => __( 'Permite cobrar una seña en el momento y dejar el resto como saldo pendiente. El saldo se agrega al pedido como una línea negativa, así la caja del día cuadra con lo que realmente entró.', 'io-punto-venta' ),
				'fields' => array(
					'deposit_enabled'     => array(
						'type'  => 'checkbox',
						'label' => __( 'Activar el cobro de seña', 'io-punto-venta' ),
					),
					'deposit_label'       => array(
						'type'  => 'text',
						'label' => __( 'Texto del saldo en el pedido y en el ticket', 'io-punto-venta' ),
					),
					'deposit_min_percent' => array(
						'type'  => 'number',
						'label' => __( 'Seña mínima (%)', 'io-punto-venta' ),
						'desc'  => __( 'Cero para no exigir un mínimo.', 'io-punto-venta' ),
						'min'   => 0,
						'max'   => 100,
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
