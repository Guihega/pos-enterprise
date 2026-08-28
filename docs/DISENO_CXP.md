# Diseno: reporte de antiguedad de cuentas por pagar (4.1.9 contable, pieza 1)

- Estado: DISENADO 2026-08-28, PR en curso. Alcance elegido por metodo tras
  #51: los datos existen completos (facturas #41) y nadie ve que se debe ni
  desde cuando; el modulo de compras termina en un dato sin vista agregada.
- Endpoint: GET /api/v1/reports/payables-aging (sin parametros: la
  antiguedad es una foto de HOY, no un rango). Permiso report.finance.

## Reglas

- Universo: SupplierInvoice con status != cancelled y saldo > 0
  (saldo = total - paid_amount, la funcion pura documentada en la 000046;
  pending y partial entran, paid queda en cero y fuera).
- Cubetas contra due_date vs hoy: current (no vencida), d1_30, d31_60,
  d61_90, d90_plus. Estandar 30/60/90; el usuario no fijo otra.
- Una fila por proveedor: supplier_uuid, name, invoices_count, balance
  total y el desglose por cubeta. Orden: balance desc. totals con la suma
  de cada cubeta y el gran total.
- Importes round 2 en la respuesta (los decimales 18,4 son de almacen).
- Sin paginacion: una fila por proveedor con deuda, acotado por naturaleza.

## Piezas

- PayablesReportService (App\Domain\Purchasing\Services): aging(), agrega
  en PHP sobre las facturas vivas con saldo (mismo criterio que balance()
  de SupplierInvoicesController:110-113, clonado).
- ReportsController::payablesAging + ruta plana reports/payables-aging.
- Tests con fechas fijas relativas a hoy (subDays) y numeros a mano.
- Sin migracion, sin permiso nuevo. Endpoints 28 -> 29.

## Fuera

Pagos programados, flujo de aprobacion de pagos, y el reporte espejo de
cuentas por COBRAR (clientes a credito): cuando el usuario lo pida.
