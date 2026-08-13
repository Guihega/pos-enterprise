# ADR-0014: Lotes y caducidad en la recepcion de compras

- Estado: aceptado
- Fecha: 2026-08-13

## Contexto

El dominio de lotes ya existia completo en Inventory: recordEntry acepta
$batch, valida RN-034 y crea el lote; hay consumo FEFO, cuarentena por
status y alertas de caducidad. Lo que faltaba era el cableado desde la
recepcion de mercancia (PR #36), que llamaba a recordEntry sin $batch y
dejaba dos docblocks marcando el diferido.

La migracion 000035 de product_batches dejo escrito que omitia
supplier_id y purchase_order_id porque el dominio Purchasing no existia,
y que se recuperarian al construir ese epic. Ese epic ya existe.

## Decision

1. Captura de lote ESTRICTA. Si products.tracks_lots es true y la linea
   de recepcion no trae batch, la peticion se rechaza con 422. Sin esto,
   la entrada suma stock sin crear lote y el consumo FEFO no encuentra de
   donde descontar: es existencia invisible para el modelo de lotes.
   La validacion vive en el servicio, no en el FormRequest, porque
   depende de un dato del producto y no de la forma del cuerpo.

2. Trazabilidad en el lote. Migracion 000049 agrega supplier_id y
   purchase_order_id a product_batches, ambas nullable con FK nullOnDelete.
   Nullable porque los lotes de ajuste de inventario, devolucion o
   traspaso no tienen orden de compra. No duplica la relacion polimorfica
   de inventory_movements.source: esa apunta al movimiento, estas permiten
   conocer el origen del lote sin recorrer movimientos.

## Descartado: fecha del lote derivada de la orden

Se evaluo tomar received_date del received_at de la OC en vez de la fecha
del dia. Se descarto al leer el codigo: received_at se sella al final de
receive(), solo cuando la orden pasa a received, de modo que en el momento
de crear el lote siempre vale null y el valor caeria a now() igualmente.
Hacerlo real exigiria aceptar una fecha de recepcion en el cuerpo, es
decir recepcion retroactiva, que es ampliacion de alcance y no se pidio.
El parametro receivedDate se conserva en InventoryService para quien lo
necesite; la recepcion de compras no lo usa.

## Consecuencias

- Recibir un producto con tracks_lots exige capturar batch. Los productos
  sin lotes no cambian de comportamiento.
- Batch no declara relaciones supplier() ni purchaseOrder(): nada las
  consume todavia. Se agregaran cuando un endpoint las necesite.
- purchase-receipts (recepcion manual sin OC) sigue pendiente y sin tabla
  definida en el maestro: es decision de alcance aparte.
