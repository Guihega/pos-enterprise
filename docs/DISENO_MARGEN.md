# Diseno: margen real por producto (4.1.9 analitico, primera pieza)

- Estado: DISENADO 2026-08-27, PR en curso. Decision de alcance del usuario:
  reportes 4.1.9 despues de cross-branch; eleccion A (margen) por metodo,
  al ser la unica partida con datos nuevos (products.cost del costeo,
  sale_items.unit_cost real por venta).
- Endpoint: GET /api/v1/reports/margin-by-product?from&to[&branch_uuid&limit]
  (ReportRangeRequest existente). Permiso: report.finance (muestra costos).

## Reglas

- Ingreso NETO de impuesto: SUM(sale_items.line_subtotal). El IVA no es
  ingreso; sales-by-product usa line_total porque reporta venta, no margen.
- Costo REAL: SUM(quantity * sale_items.unit_cost). unit_cost lo escribe
  SalesService al descontar stock (SalesService:232/269), no es el costo
  actual del producto. Un unit_cost en 0 (ventas anteriores al costeo)
  produce margen 100%: se documenta, no se corrige retroactivamente.
- margin = revenue - cost; margin_pct = margin / revenue como FRACCION
  (0.4 = 40%, patron taxes.rate); 0 cuando revenue es 0.
- Solo ventas COMPLETED en el rango, por completed_at; devoluciones FUERA,
  identico a sales-by-product (consistencia). DIFERIDO: netear
  devoluciones cuando se haga para todos los reportes de ventas a la vez.
- Orden: margen desc. limit recorta filas; los totales son de las filas
  devueltas (mismo comportamiento que sales-by-product).
- totals: rows_count, revenue, cost, margin, margin_pct.

## Piezas

SalesReportService::marginByProduct (clon de byProduct), metodo en
ReportsController, ruta plana bajo reports/, MarginByProductHttpTest
(numeros derivados a mano en el propio test). Sin migracion, sin permiso
nuevo. Endpoints: 27 -> 28.
