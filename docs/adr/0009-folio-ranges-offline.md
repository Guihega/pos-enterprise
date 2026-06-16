# 0009 — Rangos de folios por dispositivo para operacion offline

- **Status:** Accepted
- **Fecha:** 2026-06-16
- **Supersedes:** —
- **Referencias:** ADR-0004 (offline-first PWA), ADR-0005 (UUIDs), ADR-0008 (gap Fase 1→2), doc maestro sec. 87.1, EX-118

## Contexto

ADR-0008 documenta que SaleNumberGenerator::next() usa SELECT FOR UPDATE
sobre un contador central — correcto para Fase 1 (online-only), incompatible
con Fase 2 (8+ horas sin red, RN-161: "folios reservados por dispositivo
evita colisiones").

El cliente PWA necesita generar folios validos sin red. El servidor necesita
garantizar unicidad global y fiscalidad (sin huecos dentro de un rango
asignado, colisiones entre dispositivos imposibles por construccion).

## Decision

### Modelo de datos (backend)

Nueva tabla sale_number_ranges:

    CREATE TABLE sale_number_ranges (
      id               BIGSERIAL PRIMARY KEY,
      company_id       BIGINT NOT NULL REFERENCES companies(id),
      cash_register_id BIGINT NOT NULL REFERENCES cash_registers(id),
      series           VARCHAR(10) NOT NULL DEFAULT 'A',
      device_id        VARCHAR(36) NOT NULL,
      range_start      INTEGER NOT NULL,
      range_end        INTEGER NOT NULL,
      next_value       INTEGER NOT NULL,
      exhausted_at     TIMESTAMPTZ NULL,
      created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
      CONSTRAINT chk_range CHECK (range_start <= range_end),
      CONSTRAINT chk_next   CHECK (next_value >= range_start)
    );
    CREATE UNIQUE INDEX idx_snr_active ON sale_number_ranges
      (cash_register_id, series, device_id)
      WHERE exhausted_at IS NULL;

sale_number_counters (actual) pasa a representar el techo global del que
el servidor reparte rangos. Su valor es el proximo range_start disponible.

### Endpoint de reserva de rango

    POST /api/v1/folio-ranges/reserve
    Headers: X-Tenant, Authorization
    Body: { cash_register_uuid, series, device_id, size? }
    Response 201: { range_start, range_end, series, device_id }

- size default = 50 (configurable por tenant, max 500).
- El servidor hace SELECT FOR UPDATE sobre sale_number_counters (igual
  que hoy), asigna [next, next+size-1], avanza el contador y devuelve el
  rango. Idempotente por device_id + series si el rango activo no esta
  agotado (devuelve el mismo).
- Requiere conexion; el cliente lo llama al iniciar sesion de caja y cuando
  remaining <= REFILL_THRESHOLD (default 10).

### Modelo local (Dexie)

Nueva tabla folio_ranges en POSDatabase version(2):

    interface FolioRangeLocal {
      id: string           // cashRegisterUuid:series:deviceId
      cashRegisterUuid: string
      series: string
      deviceId: string
      rangeStart: number
      rangeEnd: number
      nextValue: number    // se incrementa localmente con cada folio generado
      syncedAt: string     // ISO, cuando se reservo del servidor
    }

### FolioGenerator (cliente)

    class FolioGenerator {
      async next(cashRegisterUuid, series): Promise<string>
      async needsRefill(cashRegisterUuid, series): Promise<boolean>
      async refill(cashRegisterUuid, series, tenantSlug): Promise<void>
    }

next() lee el rango activo de Dexie, toma nextValue, lo incrementa en
la misma transaccion Dexie (atomico en IndexedDB), y devuelve el folio
formateado (series + nextValue.toString().padStart(6, '0')).

Si no hay rango activo o esta agotado: lanza FolioExhaustedError (el
componente POS debe haber pre-cargado el rango al abrir caja).

### Ciclo de vida

1. CashSession.open() llama refill() si needsRefill() o no hay rango.
2. Durante la venta: FolioGenerator.next() local, sin red.
3. Al sync de la venta: servidor valida que el folio este dentro del rango
   reservado para ese device_id; si colision (EX-118), servidor reasigna
   y notifica al cliente via respuesta de sync.
4. Al cerrar caja: rango activo se marca agotado si nextValue > rangeEnd.

## Consecuencias

### Positivas
- Folios generados sin red: cumple ADR-0004 y RN-161.
- Colisiones entre dispositivos imposibles por construccion (rangos disjuntos).
- SaleNumberGenerator backend sigue siendo la unica fuente de unicidad
  global; solo cambia quien lo llama (endpoint de reserva vs. checkout).
- Tests existentes de SalesService::checkout no se modifican.

### Negativas
- Huecos posibles al final de un rango si la caja cierra antes de agotarlo.
  Aceptable fiscalmente (los huecos son rastreables por rango).
- Requiere endpoint nuevo en backend y migracion de schema.

## Como verificar

1. Test: dos dispositivos solicitan rangos del mismo cash_register bajo
   concurrencia; los rangos son disjuntos y sin huecos entre ellos.
2. Test: FolioGenerator.next() es atomico bajo multiples llamadas rapidas
   (no duplica folios).
3. Test: needsRefill() retorna true cuando rangeEnd - nextValue < 10.
4. Los tests de SaleNumberGenerator (backend) siguen pasando sin modificacion.
