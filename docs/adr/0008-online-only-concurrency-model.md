# 0008 — Gap de transición Fase 1 → Fase 2: núcleo transaccional online-only vs ADR-0004 (offline-first)

- **Status:** Accepted
- **Fecha:** 2026-06-15
- **Supersedes:** —
- **Referencias:** ADR-0004 (Cliente offline-first como PWA con IndexedDB), ADR-0005 (UUIDs como identificadores externos), `App\Domain\Sales\Services\SaleNumberGenerator`, `App\Domain\Sales\Models\SaleNumberCounter`, `App\Domain\Inventory\Services\InventoryService`, `App\Domain\Sales\Services\SalesService::checkout`

## Contexto

ADR-0004 (Fase 0, Accepted) ya decidió la dirección de producto: el cliente
POS será offline-first (PWA + IndexedDB), el servidor "no es la fuente de
verdad operacional", IDs distribuidos vía UUID (ADR-0005), folios fiscales
**reservados en rangos** (no generados secuencialmente contra un contador
central en el momento de la venta), sync idempotente y eventual consistency.
ADR-0004 marca su implementación para **Fase 2**.

El MVP actual (Fase 1, sección 86.1 del documento maestro, "POS basico
vender/cobrar... ticket simple") implementa `SalesService::checkout` con dos
mecanismos de **concurrencia pesimista online-only**, ambos dentro de la
misma transacción de la venta:

1. **`SaleNumberGenerator::next()`**: `SELECT ... FOR UPDATE` sobre
   `sale_number_counters` (clave `branch_id` + `cash_register_id` + `series`),
   incrementando un contador central por cada venta. El docblock es explícito:
   "debe llamarse SOLO dentro de una transacción que envuelva la creación
   completa de la Sale". Esto es **lo opuesto** a "folios reservados en rangos"
   (ADR-0004): hoy cada folio se obtiene del servidor en el instante del
   checkout, no de un rango pre-asignado al dispositivo.

2. **`InventoryService::recordExit()`**: valida y descuenta stock de forma
   síncrona contra `stocks`, dentro de la misma transacción. Esto asume que el
   stock leído es el stock real en ese instante — válido solo si el dispositivo
   tiene conexión viva al momento del checkout.

Esto **no es un error del MVP**: para Fase 1 (caja con conexión, ticket
simple), online-only con locks pesimistas es la implementación correcta,
simple y suficiente — produce folios sin huecos (requisito fiscal) y evita
sobreventa, con el menor esfuerzo posible. El riesgo real es que, al llegar
Fase 2, alguien intente envolver `SalesService::checkout` tal cual con una
cola de sincronización por encima, sin notar que **dos de sus piezas centrales
son estructuralmente incompatibles** con lo que ADR-0004 ya prometió
("8+ horas sin red", "folios reservados en rangos").

## Decisión

Se documenta el gap como **trabajo de rediseño explícito para el inicio de
Fase 2**, no como deuda silenciosa. Concretamente, al iniciar Fase 2:

1. **`SaleNumberGenerator` debe migrar de "contador central con lock" a
   "rangos reservados por dispositivo/caja"**, tal como especifica ADR-0004.
   Esto implica:
   - Una tabla/mecanismo de reserva de rangos (ej. `sale_number_ranges`:
     `cash_register_id`, `series`, `range_start`, `range_end`,
     `next_value`, `device_id`), consumida localmente por el cliente PWA sin
     red.
   - Un endpoint de reabastecimiento de rango, llamado por el cliente cuando
     tiene conexión y su rango local está por agotarse.
   - El `sale_number_counters` actual (contador único por
     branch+register+series) pasa a representar el **techo global** del que
     se reparten los rangos, no el folio de cada venta individual.
   - Define el contrato de datos que Fase 2 debe satisfacer; no se
     implementa en Fase 1.

2. **`InventoryService::recordExit` síncrono debe convivir con una política de
   oversell explícita** para ventas hechas offline contra stock local
   desactualizado. ADR-0004 ya referencia "doc maestro 42.1" para esta
   política — este documento solo deja constancia de que `recordExit` tal
   como existe hoy (rechaza la operación si no hay stock suficiente, síncrono)
   es el comportamiento *online* del flujo híbrido; Fase 2 debe definir el
   comportamiento *offline* (aceptar y marcar para reconciliación vs. otro
   mecanismo) sin modificar el comportamiento online ya probado.

3. **Los UUIDs de `Sale`/`SaleItem`/etc. ya están alineados con ADR-0005** (se
   generan con `Str::uuid()` en el servidor hoy, pero el contrato de columnas
   `uuid` ya existe — Fase 2 solo necesita mover la generación al cliente, sin
   cambios de esquema).

### Nota relacionada: extensibilidad del modelo `Sale` para facturación fiscal (CFDI)

Independiente de offline-first: `Sale::STATUS_*` (draft/completed/voided/
refunded) modela el ciclo de vida operativo y se usa como condición binaria en
`scopeCompleted()`/`isCompleted()` y en los reportes (`SalesSummaryService`,
`CashSessionReportService`). Facturación fiscal ("¿esta venta completada ya
generó su CFDI?") es un eje ortogonal. Cuando se implemente, **no** agregar un
quinto valor a `Sale::STATUS_*` — modelar como dato aditivo: columna nullable
(`invoiced_at`, `invoice_uuid`) en `sales`, o tabla 1:1 `sale_invoices` si el
folio fiscal requiere su propio ciclo de vida (pendiente/timbrado/cancelado).
Ambas son migraciones aditivas, sin tocar `Sale::STATUS_*` ni ningún `scope`
existente.

## Consecuencias

### Positivas

- El MVP (Fase 1) mantiene su implementación actual sin cambios: es correcta
  para su alcance y ya está probada (334+ tests).
- Fase 2 arranca con el gap mapeado contra ADR-0004 en términos concretos de
  código (`SaleNumberGenerator`, `InventoryService`), no solo de producto.
- Evita que alguien interprete "agregar Dexie + Service Worker" como
  suficiente para cumplir ADR-0004: el backend también necesita el cambio de
  modelo de folios.

### Negativas / costos

- Ninguno inmediato: documento puramente informativo, cero cambios de código.
- El costo real (rediseño de `SaleNumberGenerator` a rangos, política de
  oversell) sigue existiendo y lo absorbe Fase 2 — este documento no lo reduce,
  lo hace visible con antelación.

### Neutras

- Mismo patrón que ADR-0007 (TenantAwareJob, listener Octane): deuda
  documentada con criterio de activación explícito (inicio de Fase 2), no
  deuda silenciosa.

## Alternativas consideradas

### Alternativa A: no documentar nada, confiar en que ADR-0004 ya cubre el caso

- Descripción: ADR-0004 ya dice "folios reservados en rangos"; no hace falta
  un documento adicional.
- Por qué se descartó: ADR-0004 describe el **resultado deseado** a nivel de
  producto/arquitectura, pero no referencia el código concreto del MVP que
  habrá que cambiar. Sin este puente, el riesgo es que Fase 2 trate
  `SalesService::checkout` como una caja negra a envolver, en vez de un
  componente con dos piezas que requieren rediseño dirigido.

### Alternativa B: implementar ahora el esquema de rangos de folios, aunque Fase 2 no haya iniciado

- Descripción: crear `sale_number_ranges` y migrar `SaleNumberGenerator` ya.
- Por qué se descartó: violaría la separación de fases del documento maestro
  (86.1: "Respetar etapas/fases, NO saltarlas ni adelantar trabajo de fases
  futuras"). Además, sin el cliente PWA (Fase 2) consumiendo rangos
  localmente, el esquema quedaría sin validar en su escenario real de uso.

## Cómo verificar la decisión

Esta nota se considera correctamente aplicada si, al iniciar Fase 2:

1. El diseño de `sale_number_ranges` (o equivalente) se redacta como ADR
   nuevo que referencia este documento y ADR-0004, ANTES de modificar
   `SaleNumberGenerator`.
2. Existe un test que demuestra reabastecimiento de rango sin duplicar ni
   saltar folios bajo concurrencia (múltiples dispositivos del mismo
   `cash_register` agotando y reabasteciendo rangos).
3. La política de oversell offline (doc maestro 42.1) queda implementada como
   una rama explícita en `InventoryService`, sin alterar el comportamiento
   online actual (verificable: los tests existentes de `InventoryService`
   siguen pasando sin modificación).
4. Para CFDI: `Sale::STATUS_*` no gana un valor nuevo, y
   `scopeCompleted()`/`isCompleted()` siguen significando lo mismo que hoy.
