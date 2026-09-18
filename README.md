# IO Punto de Venta para Imprenta

Punto de venta propio para WooCommerce, pensado para el mostrador de una
imprenta. **No necesita YITH Point of Sale**: reemplaza al plugin completo.

Emite el pedido por el total del trabajo y guarda aparte lo que se cobró, así
se puede cobrar todo de una o dejar una seña con saldo pendiente sin salir del
mostrador ni tocar el backend.

---

## Qué incluye

| | |
|---|---|
| **Mostrador** | Pantalla completa para vender: buscador, grilla de productos por categoría, carrito, cliente, datos del trabajo y cobro. Funciona con dedo en una tablet y con teclado y mouse en la PC. |
| **Cobro** | Total o seña, en uno o varios métodos de pago, con vuelto y teclado numérico en pantalla. |
| **Comprobante** | Se imprime desde el navegador en rollo de 58 mm, 80 mm o en A4. |
| **Historial** | Ventas del día, propias o con saldo, con reimpresión y cobro del saldo pendiente. |
| **Cajeros** | Rol propio y permisos por acción: vender, cambiar precios, hacer descuentos, cobrar señas, cobrar saldos. |
| **Trabajos** | Fecha de entrega, horario, forma de entrega, prioridad y los campos propios que definas. |
| **Fechas de entrega** | Calcula cuándo puede estar el trabajo según los días de producción de cada producto, contando días hábiles. Reemplaza al plugin de fechas de entrega, y también funciona en la compra por la web. |

No incluye apertura y cierre de caja con arqueo, ni pantalla de producción: la
producción la maneja el **Panel Taller**, y el mostrador escribe en sus mismas
claves.

## Se apoya en lo que ya tenés

El mostrador no crea sistemas paralelos: usa los que ya están andando.

| Dato | Clave | Quién más lo usa |
|---|---|---|
| Fecha de entrega | `_wn_delivery_date` | Panel Taller (columna Entrega y su tablero) |
| Fase de producción | `_wn_fase` | Panel Taller (columna Fase). Si está el snippet de fases, el cambio pasa por `wn_phase_transition()`, así queda registrado |
| Pedido urgente | `_io_urgente` | Panel Taller |
| Archivo / enlace | `_io_drive_link` | Panel Taller y el plugin de subida de archivos |
| Cobros | `_io_pagos_historial` | Metabox «Registro de Pagos» y el módulo de finanzas |

Los cobros se guardan **en los dos lados**: en el meta del pedido y en el post
meta. Con las tablas nuevas de pedidos (HPOS) son sitios distintos, y el metabox
y la API que lee finanzas usan el post meta. Si el mostrador escribiera solo en
el del pedido, esos cobros serían invisibles y el módulo de finanzas daría la
venta por cobrada entera, porque cuando no encuentra historial asume que se
cobró el total.

Por eso el mostrador **no agrega** columnas de Entrega ni de Producción al
listado de pedidos: las que ya están muestran lo mismo. Lo único que suma es la
columna **Cobrado**, con el saldo pendiente.

---

### El enlace de Drive

El campo «Archivo / enlace» del trabajo escribe en `_io_drive_link`, que es la
clave que leen el Panel Taller y la caja **Archivos del cliente (Drive)** del
plugin de subida de archivos. Cargado desde el mostrador, el enlace aparece en
los tres lados.

Ese plugin además avisa «sin archivos todavía — este pedido no debe pasar a
producción» mientras `_io_drive_file_count` esté en cero, y ese contador solo lo
mueve el cliente al subir algo. Si el enlace lo cargás vos, los archivos
llegaron por otro camino, así que el pedido se marca como que ya los tiene y el
aviso deja de salir. Solo pasa con los pedidos cuya carpeta **no** creó el
plugin de subida: si la carpeta es suya, el contador es suyo. Se desactiva
desde Ajustes → Datos del trabajo.

## Fechas de entrega

Cada producto puede declarar sus **días de producción** en su ficha (pestaña
*Inventario*). El que no lo declara usa el valor por defecto de los ajustes
(2 días). Cuando hay varios productos, manda el más lento.

Los días se cuentan **hábiles**: se saltean los días que el taller no trabaja y
los feriados que cargues. Con la hora de corte configurada, lo que entra después
de esa hora empieza a contarse al día siguiente.

Eso alimenta tres lugares:

- **El mostrador** propone la fecha ya calculada y ofrece las siguientes fechas
  posibles como botones.
- **La compra por la web** muestra las fechas disponibles en el checkout, antes
  de pagar.
- **El Panel Taller** las ve, porque se guardan en `_wn_delivery_date`.

Los pedidos que ya tenían fecha del plugin de YITH se siguen leyendo: si
`_wn_delivery_date` está vacío, se usa `ywcdd_order_delivery_date`.

## Instalación

1. Subí el ZIP en **Plugins → Añadir nuevo → Subir plugin** y activalo.
2. Al activarse crea la página **Mostrador** (`/mostrador`), el rol **Cajero del
   mostrador** y los permisos.
3. Entrá a **Mostrador → Ajustes** y revisá los métodos de cobro y el
   comprobante.
4. Abrí **Mostrador → Abrir el mostrador**.

Requiere WooCommerce. Funciona con el guardado clásico de pedidos y con HPOS.

### Si venías usando YITH Point of Sale

Los dos pueden convivir mientras probás. Mientras YITH esté activo, este plugin
además le arregla el buscador y le agrega los datos del trabajo, igual que
antes.

Cuando quieras cortar:

1. Probá una venta completa y una con seña en el mostrador nuevo.
2. Desactivá YITH Point of Sale.
3. Listo: los pedidos viejos siguen en WooCommerce y el Panel Taller los sigue
   viendo. Lo único que se pierde son los informes de caja de YITH.

No hace falta migrar nada: los dos escriben pedidos normales de WooCommerce.

---

## Cómo se vende

1. **Buscás el producto** por nombre, código o descripción, o lo elegís de la
   grilla. El buscador acepta varias palabras sueltas y en cualquier orden, y
   busca también por SKU (incluido el de las variaciones).
2. **Trabajo a medida**: si lo que vendés no está cargado como producto, el
   primer botón de la grilla agrega una línea con descripción y precio escritos
   a mano.
3. **Tocás una línea del carrito** para cambiar cantidad, precio o dejar una
   nota de producción (la nota se imprime en el comprobante y se ve en el
   pedido).
4. **Cliente**: buscás por nombre, apellido, teléfono o empresa, o lo creás en
   el momento. Si es mostrador puro, se deja sin cliente.
5. **Datos del trabajo**: fecha de entrega con atajos (Hoy, Mañana, +3, +7,
   +15), horario, forma de entrega, prioridad y tus campos propios.
6. **Cobrar**: elegís el método y el importe. Si cobrás menos que el total, el
   resto queda como saldo pendiente.

Atajos: **F2** va al buscador, **F4** abre el cobro, **Esc** cierra.

---

## Cómo se maneja la seña

Este es el punto que cambia respecto de lo que hacías antes.

- El pedido se emite **siempre por el total del trabajo**. Eso es lo vendido.
- Lo cobrado se guarda como una **lista de cobros** en el pedido: método,
  importe, fecha y quién lo cobró.
- El saldo pendiente es la resta, y se recalcula solo cada vez que se cobra.

O sea: ya no hace falta cargar el cliente en el POS y después armar el pedido a
mano en el backend. Se hace todo en el mostrador, de una.

Cuando el cliente retira y paga el resto, se cobra desde:

- el **historial del mostrador** → abrís el pedido → *Cobrar saldo*; o
- el **pedido en WordPress** → caja *Trabajo de imprenta* → *Registrar un
  cobro*.

En los dos casos queda una nota en el pedido y el estado se ajusta solo.

### Datos que quedan en el pedido

Además de las claves compartidas de más arriba:

| Meta | Contenido |
|---|---|
| `_io_pos_order` | Marca que la venta salió de este mostrador |
| `_io_pos_cashier` / `_io_pos_cashier_name` | Quién la hizo |
| `_io_pos_paid_total` / `_io_pos_balance_due` | Cobrado y saldo, calculados del historial (sirven para filtrar y ordenar) |
| `_io_pos_change` | Vuelto entregado |
| `_io_pos_delivery_time` / `_io_pos_delivery_method` / `_io_pos_priority` | Horario, forma de entrega, prioridad |
| `_io_pos_production_notes` | Notas de producción |
| `_io_pos_field_<clave>` | Cada campo propio del trabajo |

Para el módulo de finanzas: **lo vendido** es el total del pedido
(`$order->get_total()`) y **lo cobrado** sale de `_io_pagos_historial`, la misma
lista que llena el metabox «Registro de Pagos». Cada cobro dispara además la
acción `io_pos_payment_recorded( $order, $payment )`.

Los tipos de cobro se calculan solos con los nombres que ya usás: **seña** si
queda saldo, **saldo** si completa un pedido que ya tenía pagos, y **pago** si
se cobra todo de una.

---

## Permisos

Se reparten por acción, para que un cajero no pueda hacer descuentos si no
querés:

| Permiso | Para qué |
|---|---|
| `io_pos_use` | Abrir el mostrador y vender |
| `io_pos_view_history` | Ver el historial de ventas |
| `io_pos_manage_customers` | Crear clientes |
| `io_pos_custom_item` | Cargar trabajos a medida |
| `io_pos_edit_price` | Cambiar el precio de una línea |
| `io_pos_discount` | Aplicar descuentos |
| `io_pos_partial_payment` | Cobrar una seña |
| `io_pos_collect_balance` | Cobrar saldos pendientes |

El rol **Cajero del mostrador** trae todos menos `io_pos_edit_price` y
`io_pos_discount`. Administrador y gerente de tienda los tienen todos. Para
ajustarlos alcanza con cualquier plugin de roles.

---

## Ajustes

En **Imprenta → Ajustes**, en cinco bloques: Buscador, Datos del trabajo,
Producción, Mostrador, Cobros y Comprobante.

### Campos propios del trabajo

Uno por línea:

```
clave|Etiqueta|tipo|opciones|marcas
```

- **tipo**: `text`, `textarea`, `number`, `date` o `select`
- **opciones**: separadas por coma, solo para `select`
- **marcas**: `obligatorio` y/o `ticket` (se imprime en el comprobante)

Con `clave=_otra_meta` el campo se guarda en una clave que ya usa otro módulo:
así es como «Archivo / enlace» escribe en el `_io_drive_link` del Panel Taller.
Separando varias con coma, el mismo campo alimenta a todas de una sola vez —
útil para que un dato cargado en el mostrador aparezca en otros paneles sin
tener que escribirlo dos veces.

```
material|Material|text|||
medidas|Medidas|text|||ticket
terminacion|Terminación|select|Sin terminación,Laminado mate,Troquelado||
archivo=_io_drive_link,_otra_clave|Archivo / enlace|text|||
```

Además del listado, hay un campo **Observaciones del cliente** que no se guarda
como metadato: va a la **nota del cliente** del pedido, la misma que se llena
cuando alguien compra por la web y deja un comentario. Se puede apagar desde
los ajustes.

### Métodos de cobro y fases

Los métodos se cargan igual, `clave|Etiqueta` por línea, y conviene que las
claves sean las mismas que usa el metabox de pagos (`efectivo`,
`transferencia`, `mercadopago`).

Las fases salen del Panel Taller cuando está activo; las que figuran en los
ajustes son solo el respaldo para cuando no lo está.

En Cobros se elige qué estado de WooCommerce lleva el pedido según se haya
cobrado todo, una parte o nada.

Por defecto **no se mandan los correos de WooCommerce** en las ventas del
mostrador, para no llenar de mails al cliente y a la tienda por cada venta de
mostrador. Se puede volver a activar.

---

## Ganchos

PHP:

- `io_pos_payment_recorded( $order, $payment )` — cada cobro registrado.
- `io_pos_order_created( $order, $payload )` — cada venta emitida.
- `io_pos_order_target_status( $status, $order )` — el estado que se le pone.
- `io_pos_payment_methods`, `io_pos_production_statuses`, `io_pos_job_schema`.
- `io_pos_terminal_bootstrap`, `io_pos_terminal_products_query`,
  `io_pos_terminal_history_query`, `io_pos_search_ids`,
  `io_pos_terminal_keep_styles`.
- `io_pos_production_status_changed`, `io_pos_customer_job_rows`.

La plantilla de la pantalla se puede reemplazar copiándola al tema en
`io-punto-venta/terminal.php`.

### API

Rutas propias bajo `io-pos/v1`, con los permisos de arriba:
`products`, `products/<id>/variations`, `customers`, `orders`,
`orders/<id>`, `orders/<id>/payments`.

---

## Notas y límites

- Las cantidades son números enteros, como en WooCommerce. Para vender por
  metro o por pliego conviene usar un trabajo a medida con el precio final.
- Si la tienda suma impuestos aparte del precio mostrado, el total calculado
  podría no coincidir con el de la pantalla. En ese caso el mostrador **corta la
  venta** y avisa, antes que cobrar de menos.
- La búsqueda del historial por nombre de cliente usa la búsqueda nativa de
  WooCommerce cuando está activado HPOS; con el guardado clásico busca sobre los
  datos de facturación. Por número de pedido funciona igual en los dos casos.
- El comprobante **no** se imprime solo al cerrar la venta ni al cobrar un
  saldo: se imprime con el botón. Abrir el diálogo de impresión demoraba el
  cierre. Se vuelve a activar desde Ajustes → Comprobante.
- No hay apertura ni cierre de caja con arqueo.
- En el mostrador se descargan los estilos del tema, porque le rompían los
  controles. Si necesitás mantener alguno, está el filtro
  `io_pos_terminal_keep_styles`.

### Actualizaciones

Los cambios que tocan ajustes ya guardados se aplican como migraciones con
nombre, registradas en la opción `io_pos_migrations`. No dependen del número de
versión: si una no llegó a correr —por ejemplo porque la activación selló la
versión antes de aplicarla— corre en la siguiente carga del escritorio. Una
instalación nueva las da por hechas, porque arranca con los valores por defecto.

### Pruebas

```
php tests/run-tests.php      # 169 pruebas del lado de WordPress
node tests/check-terminal.js # 22 pruebas de la pantalla
```

Del lado de PHP: fechas, validación de ajustes y campos, normalización de los
datos del trabajo, cálculo de cobros y saldos, estados del pedido, validación
del total, carga de las clases y —contra una base SQLite real— las consultas
del buscador.

Del lado del navegador: el armado de las direcciones de la API (con enlaces
permanentes bonitos y simples), el cálculo de días hábiles —con el mismo caso
que la prueba de PHP, para que las dos cuentas no se separen— y el formato de
importes, ejecutando el código tal como se publica.
