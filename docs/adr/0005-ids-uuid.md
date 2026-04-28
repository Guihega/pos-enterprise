# ADR-0005: UUIDs como identificadores externos

- **Estado**: Accepted
- **Fecha**: 2026-04-28
- **Fase**: Fase 0

## Contexto

Cada entidad necesita un identificador. Las opciones principales:

- **Bigint autoincrement**: rápido, pequeño, pero secuencial (revela cardinalidad, vulnerable a IDOR, no se puede generar offline).
- **UUID v4**: aleatorio, no se puede generar offline antes de tocar el servidor; ocupa 16 bytes; no ordenable.
- **UUID v7 / ULID**: ordenable temporalmente, generable offline; mejor para índices que UUID v4.

Restricciones del producto:

- Cliente offline necesita generar IDs sin esperar al servidor (ver ADR-0004).
- IDs no deben revelar volumen de negocio del tenant (un competidor no debería estimar nuestro volumen viendo que `sale.id = 1234567`).
- Joins eficientes en Postgres.

## Decisión

Adoptamos un **modelo dual**:

- **Internamente** (PK de Postgres): `BIGINT` autoincrement con `BIGSERIAL`. Eficiencia máxima en joins, índices más pequeños.
- **Externamente** (URL, API, sync): **UUID v4** en columna `uuid` con índice único.
- Las relaciones internas usan `BIGINT` FK; las APIs y el cliente offline usan UUIDs.
- En el futuro evaluaremos UUID v7 si el ordenamiento temporal beneficia a tablas muy grandes (sales, inventory_movements).

Reglas:

- El cliente genera `uuid` con `crypto.randomUUID()` para entidades nuevas.
- El backend devuelve siempre `uuid` en payloads, jamás `id` interno.
- Sync usa `client_uuid` (generado en cliente) como clave de idempotencia.

## Consecuencias

### Positivas

- Cliente puede crear ventas, movimientos, lo que sea, offline, sin esperar al servidor para asignar IDs.
- IDs externos no revelan volumen.
- Joins internos siguen siendo eficientes con BIGINT.
- IDOR mitigado (UUIDs no enumerables).

### Negativas

- Cada tabla tiene dos columnas identificadoras (`id` BIGINT + `uuid` UUID). Costo de espacio menor.
- Search por UUID es ligeramente más lento que por BIGINT, mitigado con índices.

### Neutras

- Mantenemos opción de migrar a UUID v7 sin breaking change (mismo formato externo, más amigable para índices).

## Alternativas consideradas

### Solo BIGINT

- Más simple.
- **Descartado**: imposible generar IDs offline sin coordinarse con servidor.

### Solo UUID v4 (PK también)

- Externo e interno son lo mismo.
- **Descartado**: índices más grandes, peor performance en joins, fragmentación de páginas en Postgres.

### Solo UUID v7

- Mejor que v4 para índices.
- **Pospuesto**: aún menos soporte universal, evaluaremos en sales/inventory_movements cuando alcancen volumen alto.

## Referencias

- Documento maestro, sección 40 (IDs distribuidos).
