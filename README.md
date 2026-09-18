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
| **Producción** | Estados internos del taller, tablero por fecha de entrega y columnas en el listado de pedidos. |

No incluye apertura y cierre de caja con arqueo: las ventas quedan registradas
en el pedido y en los informes de WooCommerce.

---

## Instalación

1. Subí el ZIP en **Plugins → Añadir nuevo → Subir plugin** y activalo.
2. Al activarse crea la página **Mostrador** (`/mostrador`), el rol **Cajero del
   mostrador** y los permisos.
3. Entrá a **Imprenta → Ajustes** y revisá los métodos de cobro y el
   comprobante.
4. Abrí **Imprenta → Abrir el mostrador**.

Requiere WooCommerce. Funciona con el guardado clásico de pedidos y con HPOS.

### Si venías usando YITH Point of Sale

Los dos pueden convivir mientras probás. Mientras YITH esté activo, este plugin
además le arregla el buscador y le agrega los datos del trabajo, igual que
antes.

Cuando quieras cortar:

1. Probá una venta completa y una con seña en el mostrador nuevo.
2. Desactivá YITH Point of Sale.
3. Listo: los pedidos viejos siguen en WooCommerce y el tablero de producción
   los sigue viendo. Lo único que se pierde son los informes de caja de YITH.

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

| Meta | Contenido |
|---|---|
| `_io_pos_order` | Marca que la venta salió de este mostrador |
| `_io_pos_cashier` / `_io_pos_cashier_name` | Quién la hizo |
| `_io_pos_payments` | Lista de cobros: `method`, `amount`, `date`, `user_id` |
| `_io_pos_paid_total` | Total cobrado |
| `_io_pos_balance_due` | Saldo pendiente |
| `_io_pos_change` | Vuelto entregado |
| `_io_pos_delivery_date` | Fecha de entrega (`Y-m-d`) |
| `_io_pos_delivery_time` / `_io_pos_delivery_method` / `_io_pos_priority` | Horario, forma de entrega, prioridad |
| `_io_pos_production_status` | Estado de producción |
| `_io_pos_production_notes` | Notas de producción |
| `_io_pos_field_<clave>` | Cada campo propio del trabajo |

Para la app de finanzas: **lo vendido** es el total del pedido
(`$order->get_total()`) y **lo cobrado** es `_io_pos_paid_total`. Cada vez que
entra plata se dispara la acción `io_pos_payment_recorded( $order, $payment )`,
que es el punto donde engancharla.

> Si el desarrollo que ya tenés para registrar señas guarda los datos con otras
> claves, hay que mapearlas: pasame cómo las guarda y lo conecto.

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

```
material|Material|text|||
medidas|Medidas|text|||ticket
terminacion|Terminación|select|Sin terminación,Laminado mate,Troquelado||
archivo|Archivo / enlace|text|||
```

### Métodos de cobro y estados

Los métodos se cargan igual, `clave|Etiqueta` por línea. Los estados de
producción también. En Cobros se elige qué estado de WooCommerce lleva el
pedido según se haya cobrado todo, una parte o nada.

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
  `io_pos_terminal_history_query`, `io_pos_search_ids`, `io_pos_board_query_args`.
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
- No hay apertura ni cierre de caja con arqueo.

### Pruebas

```
php tests/run-tests.php     # 102 pruebas del lado de WordPress
node tests/check-terminal.js # 10 pruebas de la pantalla
```

Del lado de PHP: fechas, validación de ajustes y campos, normalización de los
datos del trabajo, cálculo de cobros y saldos, estados del pedido, validación
del total, carga de las clases y —contra una base SQLite real— las consultas
del buscador.

Del lado del navegador: el armado de las direcciones de la API (con enlaces
permanentes bonitos y simples) y el formato de importes, ejecutando el código
tal como se publica.
