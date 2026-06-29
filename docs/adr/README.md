# Architecture Decision Records (ADRs)

Las ADRs documentan decisiones arquitectónicas significativas, su contexto y consecuencias. Una vez **Accepted**, no se modifican: si la decisión cambia, se crea una nueva ADR que la **Supersede**.

## Ciclo de vida

`Proposed` → `Accepted` → (`Deprecated` | `Superseded by ADR-XXX`)

## Formato

Plantilla en [`docs/adr/_template.md`](_template.md). Numeración secuencial: `NNNN-titulo-corto.md`.

## Índice

| #    | Título                                                | Estado    |
|------|-------------------------------------------------------|-----------|
| [0001](0001-stack-tecnologico.md) | Stack tecnológico base | Accepted |
| [0002](0002-monorepo.md) | Monorepo backend + frontend + docs | Accepted |
| [0003](0003-multitenant-pool-default.md) | Multi-tenancy pool por defecto, silo opcional | Accepted |
| [0004](0004-offline-first-pwa.md) | Cliente offline-first como PWA con IndexedDB | Accepted |
| [0005](0005-ids-uuid.md) | UUIDs como identificadores externos | Accepted |
| [0006](0006-postgres-rls.md) | Row Level Security en Postgres como defense-in-depth | Accepted |
| [0007](0007-tenant-context-octane.md) | TenantContext con estado estatico y compatibilidad con Laravel Octane | Accepted |
| [0008](0008-online-only-concurrency-model.md) | Gap de transicion Fase 1 -> Fase 2: nucleo transaccional online-only vs ADR-0004 | Accepted |

