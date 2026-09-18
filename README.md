# IO Punto de Venta para Imprenta

Plugin complementario de **YITH Point of Sale for WooCommerce** que corrige las
limitaciones del buscador y adapta el punto de venta al flujo de trabajo de una
imprenta: fecha de entrega, datos del trabajo, estados de producción y cobro de
seña con saldo pendiente.

No modifica ni reemplaza a YITH POS: se engancha en sus filtros públicos de PHP
y de JavaScript, así que el plugin original se puede seguir actualizando.

---

## Qué resuelve

### 1. El buscador

YITH POS busca así, de fábrica:

| Comportamiento original | Consecuencia en el mostrador |
|---|---|
| Solo busca el título completo, con un `LIKE` de la frase entera | "tarjetas 9x5" no encuentra "Tarjetas personales 9x5" |
| No busca por SKU salvo con lector de códigos | No se puede buscar por código interno |
| Devuelve 10 resultados como máximo | Media lista de productos queda fuera |
| Exige escribir 3 letras | Códigos cortos no se pueden buscar |
| Descarta del listado todo lo que WooCommerce no marque como `purchasable` | Los productos sin precio (los que se presupuestan) no aparecen nunca |
| Bloquea el clic sobre cualquier resultado sin stock | **"no me deja seleccionar" productos** |
| El listado de productos pide `yith_pos_has_price=yes` | Los productos sin precio tampoco aparecen en la grilla |

Lo que hace este plugin:

- Busca por **varias palabras sueltas** y en cualquier orden (cada palabra tiene
  que aparecer en el título, en la descripción corta o en el SKU). También
  admite frases entre comillas: `"tarjetas personales"`.
- Busca por **SKU y código de barras**, parcial o completo, incluido el SKU de
  las variaciones (escribiendo el SKU de una variación se agrega esa variación).
- Sube el límite de resultados (configurable, hasta 100).
- Baja el mínimo de letras para empezar a buscar (configurable, por defecto 2).
- Hace que **todos los resultados se puedan seleccionar**, tengan o no precio y
  tengan o no stock. El precio se puede editar después en el carrito.
- Muestra el SKU y el stock en los resultados.

Si la búsqueda no encuentra nada, no devuelve nada: YITH, al fallar su consulta,
devolvía el catálogo entero.

### 2. Fecha de entrega y datos del trabajo

Agrega al punto de venta un panel **Datos del trabajo**, que se abre desde el
menú del POS o desde el indicador flotante que muestra siempre la fecha de
entrega del pedido en curso.

El panel trae:

- Fecha de entrega, con atajos (Hoy / Mañana / +3 / +7).
- Horario de entrega, forma de entrega y prioridad (listas configurables).
- Los campos propios de la imprenta que definas (material, medidas,
  terminación, archivo…).
- Estado de producción inicial.
- Notas de producción.
- Seña, si está activada.

Los datos viajan con el pedido, se imprimen en el ticket, se ven en el detalle
del pedido de WooCommerce y, si querés, en los correos al cliente.

Opcionalmente se puede **exigir la fecha de entrega antes de cobrar**.

### 3. Producción

Estados internos del taller (Pendiente, En diseño, Esperando aprobación, En
producción, Listo para entregar, Entregado; se pueden cambiar). Son **estados
propios, no estados de WooCommerce**, a propósito: los informes de caja de YITH
POS solo cuentan los pedidos en `pending`, `processing`, `on-hold` y
`completed`, así que crear estados nuevos de WooCommerce dejaría los cierres de
caja sin esos pedidos.

Incluye:

- Menú **Imprenta → Producción**: todos los trabajos abiertos, agrupados por
  fecha de entrega, con vencidos y entregas de hoy a la vista.
- Columnas **Entrega** y **Producción** en el listado de pedidos, con el estado
  editable ahí mismo.
- Filtros por fecha de entrega (vencidos, hoy, mañana, esta semana, sin fecha) y
  por estado de producción.
- Acciones en lote para cambiar el estado de varios trabajos.
- Los trabajos quedan en `processing` en lugar de `completed` (configurable),
  así se distingue lo que ya salió de lo que sigue en el taller.

### 4. Seña y saldo pendiente

La pantalla de cobro de YITH POS no cierra la venta hasta que se pagó el total,
así que la seña se resuelve agregando al carrito una línea negativa por el saldo:
el cliente paga la seña y el resto queda anotado en el pedido.

Ventaja importante: **la caja del día cuadra**. Los informes de YITH POS suman
lo cobrado por método de pago (`_yith_pos_gateway_*`), no el total del pedido,
así que en la caja entra exactamente la seña.

Cuando el cliente retira el trabajo y paga el resto, en el pedido hay una acción
**"Registrar el cobro del saldo pendiente"** que borra la línea negativa, deja el
pedido con el total real del trabajo y anota el movimiento.

> El cobro del saldo se registra en el pedido, no en la sesión de caja del POS
> (WooCommerce no maneja pagos parciales). Si necesitás que ese cobro entre en el
> arqueo del día, conviene hacerlo como un cobro suelto en el POS.

Viene **desactivado** por defecto.

---

## Instalación

1. Descargá el repositorio como ZIP (o cloná y comprimí la carpeta).
2. La carpeta del plugin tiene que llamarse `io-punto-venta`.
3. Subilo en **Plugins → Añadir nuevo → Subir plugin** y activalo.
4. Requiere WooCommerce y YITH Point of Sale for WooCommerce activos. Si falta
   alguno, el plugin avisa y no hace nada.
5. Configuralo en **Imprenta → Ajustes**.

Compatible con el almacenamiento de pedidos clásico y con HPOS (tablas propias
de pedidos).

---

## Ajustes

Todo se configura en **Imprenta → Ajustes**, agrupado en cuatro bloques:
Buscador, Datos del trabajo, Producción y Seña.

### Campos propios del trabajo

Se definen uno por línea, con este formato:

```
clave|Etiqueta|tipo|opciones|marcas
```

- **clave**: identificador interno (se guarda como `_io_pos_field_<clave>`).
- **Etiqueta**: lo que ve la persona en el mostrador.
- **tipo**: `text`, `textarea`, `number`, `date` o `select`.
- **opciones**: separadas por coma, solo para `select`.
- **marcas**: `obligatorio` para exigirlo, `ticket` para imprimirlo en el
  comprobante. Se pueden usar las dos.

Ejemplo:

```
material|Material|text|||
medidas|Medidas|text|||ticket
terminacion|Terminación|select|Sin terminación,Laminado mate,Troquelado||
cantidad|Cantidad de pliegos|number|||
archivo|Archivo / enlace|text|||
```

### Estados de producción

Uno por línea, en el orden del flujo de trabajo:

```
pendiente|Pendiente
diseno|En diseño
produccion|En producción
listo|Listo para entregar
entregado|Entregado
```

Las claves de **estado inicial** y **estado final** tienen que coincidir con
alguna de esta lista.

---

## Datos que guarda en el pedido

| Meta | Contenido |
|---|---|
| `_io_pos_delivery_date` | Fecha de entrega, siempre en formato `Y-m-d` |
| `_io_pos_delivery_time` | Horario elegido |
| `_io_pos_delivery_method` | Forma de entrega |
| `_io_pos_priority` | Prioridad |
| `_io_pos_production_status` | Estado de producción |
| `_io_pos_production_notes` | Notas de producción |
| `_io_pos_field_<clave>` | Cada campo propio |
| `_io_pos_deposit` | Seña cobrada |
| `_io_pos_balance_due` | Saldo pendiente |
| `_io_pos_job_total` | Total real del trabajo |

Todo lo que manda el navegador se vuelve a validar en el servidor contra los
campos configurados: las fechas se normalizan, las opciones que no existen se
descartan y los importes se recalculan desde el pedido real.

---

## Ganchos para extender

PHP:

- `io_pos_job_schema` — campos del panel del trabajo.
- `io_pos_production_statuses` — estados de producción.
- `io_pos_search_ids` — IDs que devuelve el buscador.
- `io_pos_register_config` — configuración que recibe la app del POS.
- `io_pos_board_query_args` — consulta del tablero de producción.
- `io_pos_customer_job_rows` — filas que ve el cliente.
- `io_pos_order_saved`, `io_pos_production_status_changed`, `io_pos_balance_collected`.

JavaScript (todos los filtros de YITH POS siguen disponibles; este plugin usa
`yith_pos_cart_generated_order`, `yith_pos_header_menu_items`,
`yith_pos_receipt_order_data_elements`, `yith_pos_search_results_product_name`,
`yith_pos_enable_modal_on_click_product_name_searchbar` y
`yith_pos_show_stock_badge_in_search_results`).

---

## Notas técnicas

La app del punto de venta de YITH es React ya compilado. Casi todo lo que hace
este plugin pasa por filtros públicos, pero dos cosas necesitan hablar con la
instancia de React, porque YITH no expone otra forma:

- bajar el mínimo de 3 letras del buscador;
- agregar la línea del saldo antes de abrir la pantalla de cobro.

Las dos están aisladas en un único puente (`bridge` en `assets/js/pos.js`), con
protección ante fallos: si una futura versión de YITH cambia su estructura
interna, esas dos funciones dejan de estar disponibles (la seña se oculta sola) y
todo lo demás sigue funcionando igual.

### Pruebas

```
php tests/run-tests.php
```

Prueba la lógica de fechas, la validación de ajustes y de campos, la
normalización de los datos del pedido y —contra una base SQLite real— las
consultas del buscador.
