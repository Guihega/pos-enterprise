# Cobertura del alcance original — julio 2026

Estado del repo al escribir esto: `main` = a3adfab, suite 572 passed (1807 assertions).
Actualizado en PR #22 (hueco 2 cerrado): suite 582 passed (1829 assertions).

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
| 4.1.5 **Compras** | **Ausente** | **Dominio inexistente.** Ver hueco 1 |
| 4.1.6 Ventas | Operable | Checkout, pagos multiples, cancelacion, devoluciones. Sin apartados, cotizaciones, gift cards, vales, propinas, comisiones, multiples carritos, suspension de venta (DIFERIBLE) |
| 4.1.7 Caja | Operable | Apertura, movimientos, cierre, reporte Z. Sin corte X, sin handover de cajero, sin multi-moneda, sin auto-corte por horario (OPERATIVO/DIFERIBLE) |
| 4.1.8 Clientes | Basico | CRUD. Sin credito (decision de negocio: diferido), sin datos fiscales RFC, sin direcciones ni telefonos multiples, sin consentimientos GDPR/ARCO (OPERATIVO) |
| 4.1.9 Reportes | Parcial | 6 endpoints de ~45. Ventas por producto y por cajero cerrados en PR #24. Ver hueco 3 |

## Los tres huecos que duelen

### 1. Compras (4.1.5) — OPERATIVO

Cero codigo. No existe el dominio, ni proveedores, ni ordenes de compra, ni recepcion,
ni facturas de proveedor, ni cuentas por pagar.

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

### 3. Reportes (4.1.9) — PARCIAL

**Verificado contra el repo: las 4 rutas que declaraba este documento eran exactas.**

Estado tras el PR #24 (6 endpoints bajo `Route::prefix('reports')`):

| Endpoint | Origen |
|---|---|
| `sales-summary` | Ya existia. Resumen de UN dia |
| `consolidated/sales-daily`, `consolidated/inventory`, `consolidated/branch-comparison` | Ya existian (maestro 46.6) |
| `sales-by-product` | **PR #24**. Rango de fechas, lista completa |
| `sales-by-cashier` | **PR #24**. Rango de fechas, con ticket promedio |

Hallazgo al verificar: de los operativos basicos que este documento listaba como
faltantes, **dos ya estaban servidos dentro de `sales-summary`** y nadie lo habia
notado: `totals.average_ticket` (ticket promedio) y `payments` (desglose por metodo
de pago). No son huecos.

Quedan del bloque operativo: **productos sin venta** y **diferencias de caja**. El
bloque analitico (RFM, cohorts, LTV, forecast) y el contable (IVA, IEPS, estado de
resultados) siguen DIFERIBLE.

Nota de diseno del PR #24: los reportes nuevos usan rango `from`/`to` obligatorio y
validado (422 si falta o esta malformado), a diferencia de los consolidados, que leen
`query('from')` sin validar. Si se tocan los consolidados, conviene alinearlos.

## Que NO es un hueco

- Credito a clientes / CxC: **decision de negocio** documentada, no habra creditos
  operando por ahora (ADR-0012).
- Nota de credito y CFDI: dependen de un modulo de facturacion que no existe (4.2.1).
- RN-088 cambios atomicos: se puede hacer hoy en dos pasos (devolucion + venta nueva).
  Es comodidad, no necesidad, salvo que los cambios sean frecuentes en la operacion.
- Permisos cross-branch / gerente regional (ADR-0010): no es una feature, es un cambio
  al modelo de autorizacion. Varios PRs y riesgo de regresion en toda la suite. Merece
  sesion limpia con diseno discutido antes de tocar codigo.

## Orden sugerido si se retoma

1. ~~Verificar el hueco 2~~ **HECHO en PR #22**: la hipotesis era falsa, ver arriba.
2. Reportes operativos basicos: **parcial en PR #24** (por producto y por cajero).
   Quedan productos sin venta y diferencias de caja.
3. Compras (el modulo grande; conviene sesion dedicada). **El unico hueco intacto.**

Ninguno es urgente por decision del usuario. El sistema opera.
