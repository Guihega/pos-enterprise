# Diseno: modulo de costeo (landed cost -> precio de venta)

Estado: IMPLEMENTADO en `b8ea0b0` (#45, squash, 2026-08-21). Suite 682
passed. Diferido de v1 anotado como decision: NO hay update de lineas;
una corrida draft equivocada se abandona y se crea otra (barato, y evita
el flujo de recalculo con validacion de edicion). El update, si algun
dia duele, es alcance nuevo.
Origen: peticion del usuario 2026-08-21. Anclas verificadas en repo:
products.cost / price / compare_at_price / min_price (decimal 18,4,
CHECK no-negativos, migracion 000011); unit_cost en purchase_orders
(neto tax-exclusive, 000047). NO existe landed cost, flete ni margen.

## Concepto central: la CORRIDA DE COSTEO (costing run)

Un viaje/lote de compra con N productos. Refleja el caso real: la
gasolina es DEL VIAJE, no de un producto; se prorratea entre lo que
vino en el.

costing_runs: uuid, company_id, name, status (draft|confirmed),
  freight_total, other_costs_total, notes, confirmed_at, user_id.
costing_run_lines: run_id, product_id, pack_description (bulto/caja),
  pack_price, units_per_pack, packs_qty, waste_pct, margin_pct,
  extra_cost (costos especificos de ESTA linea), computed_* (abajo).

## Formulas (por linea)

units      = units_per_pack * packs_qty
base_unit  = pack_price / units_per_pack
freight_u  = prorrateo del freight+other del run (ver decision D4)
merma      = (base_unit + freight_u + extra_cost/units) / (1 - waste_pct)
             [waste_pct < 1; 422 si >= 1]
costo_real = resultado anterior (landed unit cost)
precio     = segun decision D3 (markup vs margen sobre precio)

Todo decimal(18,4), redondeo solo al final, patron del sistema.

## Flujo

1. POST /costing-runs (draft) + lineas.
2. GET calcula y muestra costos/precios sugeridos (preview siempre).
3. POST /costing-runs/{uuid}/confirm: segun D1, escribe products.cost
   (y price si el usuario acepta el sugerido) y congela el run.
Permisos: dominio Purchasing (COSTING_VIEW/CREATE/CONFIRM, 3 pasos).
Servicio nuevo (regla: metodo nuevo antes que tocar entregado).

## Decisiones (DECIDIDO 2026-08-21, orden de continuar del usuario:
recomendaciones del copiloto aplicadas; D3 disuelta en campo por linea)

- D1 = (b): confirmar escribe products.cost; price queda SUGERIDO y se
  acepta por producto en un paso aparte.
- D2 = corrida multi-producto; el producto suelto es corrida de 1 linea.
- D3 = campo margin_type por linea ('markup' | 'on_price'): soporta
  ambas formulas, el usuario elige por dato, no por diseño.
- D4 = prorrateo del flete por VALOR de linea (packs_qty * pack_price).

## Decisiones originales planteadas (referencia)

- D1 Efecto de confirmar: (a) escribe products.cost y products.price;
  (b) solo products.cost y el price queda sugerido para aceptar por
  producto; (c) solo informe, sin escribir. Recomendacion: (b).
- D2 Unidad de trabajo: corrida multi-producto (propuesta) vs costeo
  por producto suelto. Recomendacion: corrida (cubre el caso suelto
  con una corrida de 1 linea).
- D3 Margen: markup sobre costo (precio = costo * (1+m)) vs margen
  sobre precio (precio = costo / (1-m)). Cambia el numero: 30% sobre
  costo 100 -> 130; sobre precio -> 142.86. SIN recomendacion: regla
  de negocio pura.
- D4 Prorrateo del flete: por valor de linea (packs*pack_price, mas
  fiel economicamente) vs por unidades (mas simple de explicar).
  Recomendacion: por valor, configurable por run si se pide despues.

## Fuera de alcance v1 (diferido documentado)

Historico de costos por producto; recosteo automatico al recibir OCs;
impuestos dentro del costeo (unit_cost del sistema es neto); multi-
moneda; integracion con product_batches.
