# ADR-0006: Row Level Security en Postgres como defense-in-depth

- **Estado**: Accepted
- **Fecha**: 2026-04-28
- **Fase**: Fase 0 (decisión); políticas RLS implementadas con cada migración tenant-aware.

## Contexto

ADR-0003 establece multi-tenancy pool con `company_id` en cada tabla. La consulta tenant-aware se aplica en la capa de aplicación (Eloquent global scopes).

Pero **un solo bug** puede romper esto:

- Un developer escribe `DB::table('sales')->where(...)->get()` y olvida el filtro.
- Un developer agrega un endpoint nuevo y olvida el middleware.
- Un global scope se desactiva accidentalmente con `withoutGlobalScopes()`.

Cualquiera de estos casos filtra datos cross-tenant. Inaceptable para un SaaS.

## Decisión

Activamos **Row Level Security (RLS) en Postgres** como segunda barrera obligatoria sobre todas las tablas tenant-aware.

- Cada tabla tenant-scoped tiene `ENABLE ROW LEVEL SECURITY`.
- Política universal: `USING (company_id = current_tenant_id())`.
- El middleware de tenant ejecuta `SET LOCAL app.current_tenant_id = ?` al inicio de cada request.
- Si por error la query no incluye filtro, RLS lo aplica de todos modos.
- Si por error el middleware no se ejecutó, `current_tenant_id() = 0` y la query devuelve cero filas (fail-secure).

Excepciones:

- Migrations corren con un rol distinto (`app_migrator`) que tiene `BYPASSRLS`.
- Jobs de sistema (cron globales) usan rol especial.
- Super-admin en panel SaaS setea `app.is_super_admin = true` y una política `BYPASS_RLS_FOR_SUPER_ADMIN` lo permite.

## Consecuencias

### Positivas

- Aislamiento garantizado a nivel de motor de BD, no solo a nivel de aplicación.
- Una capa "tonta" (RLS) cubre olvidos en la capa "lista" (Eloquent).
- Auditable: `pg_policies` lista todas las políticas activas.
- Cumple un requisito frecuente en auditorías de seguridad / SOC 2.

### Negativas

- Overhead pequeño en cada query (un check adicional). Medible pero aceptable.
- Developers pueden confundirse al escribir migraciones: deben usar el rol correcto o las políticas bloquearán la migración.
- Tests deben establecer el contexto de tenant correctamente, o aparentan "no encontrar datos".

### Neutras

- En modo desarrollo local, `app.current_tenant_id` se setea a 1 por defecto para no entorpecer pruebas exploratorias en `psql`.

## Alternativas consideradas

### Confiar 100% en la capa de aplicación

- Más simple, menos overhead.
- **Descartado**: el riesgo de filtración por un bug es alto y catastrófico.

### Schema separado por tenant

- Aislamiento físico.
- **Descartado**: no escala (ver ADR-0003).

### Cada tenant en su propia DB

- Aislamiento total.
- **Descartado para Pool**: costo, complejidad. Reservado para Enterprise (ADR-0003).

## Implementación

Cada migración de tabla tenant-aware sigue este patrón:

```sql
CREATE TABLE products (
    id BIGSERIAL PRIMARY KEY,
    uuid UUID NOT NULL UNIQUE DEFAULT gen_random_uuid(),
    company_id BIGINT NOT NULL REFERENCES companies(id),
    -- otros campos
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_products_company ON products(company_id);

ALTER TABLE products ENABLE ROW LEVEL SECURITY;

CREATE POLICY products_tenant_isolation ON products
    USING (company_id = current_tenant_id());

CREATE POLICY products_super_admin_bypass ON products
    USING (current_setting('app.is_super_admin', TRUE) = 'true');
```

La función `current_tenant_id()` se define en `docker/postgres/init/01-init.sql`.

## Referencias

- Documento maestro, sección 45.5 (RLS).
- ADR-0003 (multitenant).
