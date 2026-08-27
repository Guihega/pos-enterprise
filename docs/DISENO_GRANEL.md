# Diseno: venta a granel (productos por peso) — backend

- Estado: IMPLEMENTADO en 1ebc2b8 (#50, 2026-08-27). D1 aplicada por
  defecto del copiloto = (a); PENDIENTE DE CONFIRMAR por el usuario.
- Alcance del usuario: "cajero elige el producto, la bascula da el peso,
  importe = peso x precio". Sin etiquetas con peso embebido (no aplica
  parseo EAN-13). Terminales de pago: fase posterior, fuera.
- Origen: maestro 4.1.3 (linea 392), CU flujo 1c (2015), EX-163 (2743),
  48.4 Bascula (8154-8166). COBERTURA 4.1.3: "productos por peso" DIFERIBLE.

## 1. Division de trabajo (maestro 48.4)

La bascula fisica (RS-232/USB-CDC) la lee un agente local (ScaleBridge)
que expone ws://localhost:9101/scale; el FRONTEND escucha y autocompleta
la cantidad cuando el peso es estable. El backend NO lee la bascula.
Frontend: despues, por decision del usuario. Este diseno deja el backend
listo para recibir ese peso y hacerlo fiable.

## 2. Lo que ya existe (verificado)

- units.category = weight, units.is_decimal; KG (factor 1000) y G
  provisionados por CatalogProvisioner (lineas 42-44).
- products.allow_decimals (migracion 000011:96, default false): el
  interruptor "acepta cantidades fraccionarias". NADIE lo aplica en app/.
- sale_items.quantity decimal(18,4); StoreSaleRequest:60 valida
  numeric gt:0 max:9999999.9999 para todo producto.
- products.weight / weight_unit: peso del EMPAQUE, no aplica.

## 3. Gaps

G1 (el slice): sin gate, un producto por pieza acepta 0.35 unidades y un
producto por peso no distingue peso de bascula de peso tecleado.
G2 (EX-163): bascula desconectada -> captura manual CON AUTORIZACION. No
existe.

## 4. Reglas

- La cantidad de una linea se expresa SIEMPRE en la unidad del producto
  (KG -> kilos con fraccion, G -> gramos). La conversion la hace la
  terminal con units.factor; el backend no convierte.
- Cantidad fraccionaria permitida SOLO si product.allow_decimals; en caso
  contrario la cantidad debe ser entera: 422 en servicio (leccion 25),
  InvalidArgumentException -> 422 existente. Aplica a ventas y
  devoluciones. Inventario (ajustes, transferencias, recepciones):
  DIFERIDO, misma regla cuando se toque cada uno.
- Precision: hasta 4 decimales (escala de la columna); las basculas
  comerciales dan 3 (gramos). Importe de linea = round(qty * unit_price, 2),
  como hoy; ningun redondeo especial de granel en backend (el redondeo a
  0.50 en efectivo, si se quiere, es del pago, no de la linea: fuera).
- Trazabilidad del peso: sale_items.quantity_source enum
  ('scale','manual'), nullable. OBLIGATORIO cuando product.allow_decimals;
  null en productos por pieza. Migracion 000053.
- quantity_source = 'manual' en producto por peso exige el permiso nuevo
  sale.weight.manual (EX-163 "con autorizacion"): 403 si el usuario no lo
  tiene. all(): 70 -> 71.

## 5. Decision del usuario

D1 — Quien autoriza la captura manual de peso (bascula desconectada):
  (a) GERENTE y SUPERVISOR por defecto (CAJERO no); ADMIN via all().
  (b) Tambien CAJERO (sin control real; el permiso queda decorativo).
  Recomendacion: (a). Que se rompe con (b): cualquier cajero teclea
  el peso y la bascula deja de ser la fuente de verdad.
  RESPUESTA: (a) aplicada por DEFECTO DEL COPILOTO ante silencio del
  usuario (2026-08-27). CONFIRMAR: cambiarla es una linea en Roles.php.

## 6. Piezas (PR unico)

- Migracion 000053: sale_items.quantity_source string(10) nullable
  (plantilla 000047; ciclo migrate->rollback->migrate).
- Permissions: SALE_WEIGHT_MANUAL = 'sale.weight.manual' (3 pasos;
  matriz segun D1).
- StoreSaleRequest / StoreSaleReturnRequest: items.*.quantity_source
  in:scale,manual, nullable (forma; la regla de negocio va al servicio).
- SalesService (y SaleReturnService): gate de entero vs allow_decimals,
  gate de quantity_source obligatorio en allow_decimals, gate de permiso
  para 'manual'. Persistir quantity_source en la linea.
- SaleItem: quantity_source en fillable; SaleItemResource lo expone.
- ProductResource / Store-UpdateProductRequest: verificar que
  allow_decimals se pueda leer y escribir por API (si ya esta, nada).
- Tests con numeros a mano: 2.345 kg x 89.90 = 210.82; producto por
  pieza con 0.5 -> 422; peso sin source -> 422; manual sin permiso ->
  403; manual con GERENTE -> 201 y source persistido; sync offline no
  se rompe (verificar SyncBatchService al implementar).

## 7. Fuera de este diseno

Agente ScaleBridge y lectura del puerto (terminal), umbral de peso
minimo y estabilidad (frontend), etiquetas con peso embebido (el usuario
no las usa), terminales de pago (48.6, fase posterior).
