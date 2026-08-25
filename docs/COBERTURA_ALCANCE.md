# Cobertura del alcance original — julio 2026

Estado del repo al escribir esto: `main` = 94d9c53, suite 612 passed (1965 assertions).
Historial: PR #22 cerro el hueco 2 (582 passed). PR #27 cerro el hueco 3 (604 passed). PR #32 abrio el hueco 1 con el maestro de proveedores (612 passed).

## Que mide este documento

`CIERRE_2026-07.md` documenta lo trabajado en las sesiones recientes (PRs #8 al #20).
**No es un inventario de cobertura del alcance original**: un caso de uso del maestro
que nunca se toco no aparece ahi ni como implementado ni como diferido.

Este documento cruza la seccion 4 del maestro (ALCANCE FUNCIONAL TOTAL, lineas 356-688)
contra el codigo real: rutas en `routes/api.php`, servicios en `app/Domain/` y migraciones.

## Como leer el alcance del maestro

El maestro divide el alcance en dos bloques:

- **4.1 Nucleo (Fases 1-9)**: 9 modulos, ~180 capacidades. Es contra esto que se mide
  si el producto es funcional.
- **4.2 Avanzado (Fases 10-18)**: facturacion electronica, pagos, movil, e-commerce, CRM,
  API publica, inteligencia, internacionalizacion, contabilidad. **No es requisito para operar.**
- **4.3 Transversales**: pendiente de revisar.

Casi todos los diferidos de los ADRs 0010-0013 caen en 4.2 (CFDI y nota de credito en
4.2.1, PWA en 4.2.3). Eso no es deuda del nucleo: es alcance de fases posteriores.

**Advertencia importante**: la lista del nucleo es un catalogo de ambicion, no un minimo
operable. En 4.1.1 conviven 'Login con PIN' (indispensable) con 'SSO SAML' y 'Biometria'.
En 4.1.4 conviven 'Stock en tiempo real' con 'Kardex valorizado PEPS/UEPS/promedio' y
'Maquila y produccion'. Medir cobertura al 100% contra los ~180 bullets da un porcentaje
bajo y sin significado. Por eso la matriz de abajo lleva criticidad operativa.

## Criterio de criticidad

| Nivel | Definicion |
|---|---|
| BLOQUEANTE | Sin esto no se puede abrir la tienda y vender |
| OPERATIVO | Se puede vender sin ello, pero duele a diario |
| DIFERIBLE | Mejora o requisito de cliente grande, no de operacion |

## Veredicto

**El circuito de venta esta completo y operable**: abrir caja, buscar producto, cobrar con
multiples metodos, devolver, cerrar caja con reporte. Un POS puede operar hoy.

Lo que falta es administracion alrededor de la venta, no la venta.

## Matriz por modulo del nucleo

| Modulo | Estado | Que falta que importe |
|---|---|---|
| 4.1.1 Identidad | Operable | Sin MFA, sin SSO, sin biometria (DIFERIBLE). Reset de password resuelto en PR #20 |
| 4.1.2 Organizacional | Operable | Sucursales y almacenes con CRUD completo (update y deactivate de almacenes cerrados en PR #22) |
| 4.1.3 Catalogo | Operable | Unidades e impuestos con apiResource completo (ya existia; la matriz lo reportaba mal). Sin variantes, combos, kits, recetas, productos por peso, promociones, cupones, listas de precios, precios programados (DIFERIBLE) |
| 4.1.4 Inventario | Operable | Stock, lotes, movimientos, transferencias con solicitud. Sin conteos ciclicos, series, ubicaciones fisicas, kardex valorizado, analisis ABC, mermas por categoria (DIFERIBLE) |
| 4.1.5 **Compras** | **Parcial** | Maestro de proveedores operable (PR #32). Sin ordenes de compra, sin recepcion, sin facturas de proveedor, sin cuentas por pagar. Ver hueco 1 |
| 4.1.6 Ventas | Operable | Checkout, pagos multiples, cancelacion, devoluciones. Sin apartados, cotizaciones, gift cards, vales, propinas, comisiones, multiples carritos, suspension de venta (DIFERIBLE) |
| 4.1.7 Caja | Operable | Apertura, movimientos, cierre, reporte Z. Sin corte X, sin handover de cajero, sin multi-moneda, sin auto-corte por horario (OPERATIVO/DIFERIBLE) |
| 4.1.8 Clientes | Basico | CRUD. Sin credito (decision de negocio: diferido), sin datos fiscales RFC, sin direcciones ni telefonos multiples, sin consentimientos GDPR/ARCO (OPERATIVO) |
| 4.1.9 Reportes | Operable | 8 endpoints. Productos sin venta y diferencias de caja cerrados en PR #27. Bloque analitico y contable DIFERIBLE |

## Los tres huecos que duelen

### 1. Compras (4.1.5) — PARCIAL (PRs #32, #34, #36, #39-#42)

El dominio `Purchasing` YA existe. Operables: el maestro de proveedores
(migracion 000046, `/suppliers` con CRUD y baja logica) y las ordenes de
compra (migraciones 000047 y 000048) con el ciclo completo
`draft -> submitted -> approved -> received` mas cancelacion. La recepcion
mueve stock via `InventoryService` y admite entregas parciales: la OC solo
pasa a `received` cuando todas las lineas estan completas.

Van 19 de los 22 endpoints que define 29.7: TODO el alcance acordado
esta entregado. El #41 entrego `supplier-invoices` completo y el #42
`GET /suppliers/{uuid}/products` (fuente original del #42: OCs no
canceladas del proveedor). El #43 (`57d8b26`, 2026-08-21) pago
[deuda-19] sumando DOS fuentes: `product_batches.supplier_id` e
`inventory_movements.supplier_id`; los productos comprados sin OC y
ajustados con proveedor via `POST /inventory/adjust` ya SI son
listables. Los 3 restantes son `purchase-receipts`, FUERA DE
ALCANCE por decision del usuario, ver mas abajo.
El PATCH de la OC en draft (maestro linea 5968, Actualizar si draft) ya
esta entregado: PATCH parcial con permiso propio PURCHASE_ORDER_UPDATE,
reemplazo de lineas en bloque con recalculo de totales, y 409 si la
orden no esta en draft. supplier_uuid y branch_uuid NO son
modificables por decision de alcance. Ya no quedan diferidos en
Compras. Los lotes y la caducidad en la recepcion se
entregaron en el PR de lotes (ADR-0014): recibir un producto con
tracks_lots exige capturar batch, y el lote guarda supplier_id y
purchase_order_id (migracion 000049). Con los PRs #41 y #42 son 19 de
22 endpoints: los 5 de facturas (listar, crear, conciliar, pagar y
saldo) y los productos del proveedor.

**Matiz que evita el panico**: el stock SI puede cargarse hoy via
`InventoryService::recordEntry` con `TYPE_ENTRY`, expuesto en `POST /inventory/adjust`.
La tienda puede surtirse. Lo que falta es el proceso administrativo alrededor: registrar
al proveedor, la orden, el costo formal y el pago.

Es el modulo grande pendiente. No bloquea vender.

### 2. CRUD de almacenes, unidades e impuestos — CERRADO

**VERIFICADO contra el repo (julio 2026). La hipotesis de este documento era falsa.**

Lo que se comprobo, archivo por archivo:

- `CatalogProvisioner` provisiona **solo unidades e impuestos**. No toca `Warehouse`:
  el modelo vive en el dominio `Inventory`, no en `Catalog`. La hipotesis agrupaba las
  tres entidades por conveniencia narrativa, no por como esta organizado el codigo.
- **Unidades e impuestos SI tenian CRUD**: `Route::apiResource('units')` y
  `apiResource('taxes')` en `routes/api.php`, con las cinco acciones. La afirmacion
  "sin CRUD" era incorrecta.
- **Almacenes tenian `index`, `show` y `store`**, no cero endpoints. Faltaban `update`
  y `deactivate`, cerrados en el PR #22 siguiendo el patron de `BranchesController`
  (PATCH para editar, POST `/deactivate` para baja logica, nunca DELETE).

Leccion de metodo: la fila estaba marcada SIN VERIFICAR y las tres afirmaciones que
contenia resultaron falsas. Las filas de esta matriz escritas sin grep del archivo real
merecen la misma desconfianza.

### 3. Reportes (4.1.9) — CERRADO (PR #27)

**Verificado contra el repo: las 4 rutas que declaraba este documento eran exactas.**

Estado tras el PR #24 (6 endpoints bajo `Route::prefix('reports')`):

| Endpoint | Origen |
|---|---|
| `sales-summary` | Ya existia. Resumen de UN dia |
| `consolidated/sales-daily`, `consolidated/inventory`, `consolidated/branch-comparison` | Ya existian (maestro 46.6) |
| `sales-by-product` | **PR #24**. Rango de fechas, lista completa |
| `sales-by-cashier` | **PR #24**. Rango de fechas, con ticket promedio |
| `products-without-sales` | **PR #27**. Rango, con `last_sold_at` del historico completo |
| `cash-differences` | **PR #27**. Sesiones cerradas por rango, faltantes y sobrantes |

Hallazgo al verificar: de los operativos basicos que este documento listaba como
faltantes, **dos ya estaban servidos dentro de `sales-summary`** y nadie lo habia
notado: `totals.average_ticket` (ticket promedio) y `payments` (desglose por metodo
de pago). No son huecos.

Cerrados en el **PR #27**: `products-without-sales` (antijoin con `NOT EXISTS`, con
`last_sold_at` para distinguir "nunca se vendio" de "no se vendio este mes") y
`cash-differences` (sesiones cerradas por rango, con faltantes, sobrantes y neto).

Hallazgo al verificar, mismo patron que `average_ticket` en el #24: **las diferencias
de caja no habia que calcularlas**. `CashService::closeSession()` ya persiste
`expected_amount`, `counted_amount` y `difference` en `cash_sessions`. El endpoint solo
agrega por rango. Los cinco permisos `REPORT_*` tambien existian ya, y el helper
`reports()` de `Roles::defaultMatrix()` ya los componia: no se toco autorizacion.

El bloque analitico (RFM, cohorts, LTV, forecast) y el contable (IVA, IEPS, estado
de resultados) siguen DIFERIBLE.

Nota de diseno del PR #24: los reportes nuevos usan rango `from`/`to` obligatorio y
validado (422 si falta o esta malformado), a diferencia de los consolidados, que leen
`query('from')` sin validar. Si se tocan los consolidados, conviene alinearlos.

## Que NO es un hueco

- Credito a clientes / CxC: **decision de negocio** documentada, no habra creditos
  operando por ahora (ADR-0012).
- Nota de credito y CFDI: dependen de un modulo de facturacion que no existe (4.2.1).
- RN-088 cambios atomicos: se puede hacer hoy en dos pasos (devolucion + venta nueva).
  Es comodidad, no necesidad, salvo que los cambios sean frecuentes en la operacion.
- `purchase-receipts` (recepcion manual sin OC e historico de recepciones):
  **evaluado y pospuesto a una version futura** por decision del usuario.
  Recibir sin orden YA se puede: `POST /inventory/adjust` con `TYPE_ENTRY`
  mueve stock y acepta lote. Si el proveedor va a facturar, el camino
  correcto es crear la OC aunque sea despues del hecho, y eso opera hoy.
  El historico TAMPOCO falta como dato: cada recepcion escribe en
  `inventory_movements` con la OC como source polimorfico, usuario,
  almacen y costo; lo que no hay es un endpoint que lo presente agrupado.
  Construirlo de verdad exigiria que `receive()` escribiera en una tabla
  nueva, es decir modificar un metodo ya entregado y probado (#36, #39).
  Ademas el maestro NO define la tabla: la linea 7834 es prosa y el SQL
  de Purchasing (4477-4570) no la incluye, asi que habria que diseñarla.
  Criterio de reapertura: revisar DESPUES de `supplier-invoices`, porque
  `/supplier-invoices/{uuid}/match` (conciliar con OC/recepcion) mostrara
  que forma necesita una recepcion para ser conciliable. Diseñar la tabla
  antes de saber eso es adivinar.
  **Revision hecha tras #41 (criterio cumplido)**: `match()` concilia por
  TOTALES contra lo recibido, calculado desde `purchase_order_items`. Una
  recepcion conciliable NO necesita tabla propia. El posponimiento queda
  reforzado: reabrirlo seria valor administrativo, no deuda tecnica.
- Permisos cross-branch / gerente regional (ADR-0010): no es una feature, es un cambio
  al modelo de autorizacion. Varios PRs y riesgo de regresion en toda la suite. Merece
  sesion limpia con diseno discutido antes de tocar codigo.

## Orden sugerido si se retoma

1. ~~Verificar el hueco 2~~ **HECHO en PR #22**: la hipotesis era falsa, ver arriba.
2. ~~Reportes operativos basicos~~ **HECHO**: parcial en PR #24 (por producto y por
   cajero), CERRADO en PR #27 (productos sin venta y diferencias de caja).
3. ~~Compras: maestro de proveedores~~ **HECHO en PR #32**: dominio `Purchasing`,
   `/suppliers` operable. Ordenes de compra (#34), recepcion con lotes
   (#36, #39), PATCH en draft (#40) y facturas con conciliacion, pagos y
   saldo (#41) y productos del proveedor (#42) tambien entregados: el
   alcance acordado esta COMPLETO. **Ya no queda ningun hueco intacto.**

Ninguno es urgente por decision del usuario. El sistema opera.
