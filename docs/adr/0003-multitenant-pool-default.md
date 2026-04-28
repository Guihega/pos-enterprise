# ADR-0003: Multi-tenancy pool por defecto, silo opcional

- **Estado**: Accepted
- **Fecha**: 2026-04-28
- **Fase**: Fase 0

## Contexto

El producto es un SaaS multi-tenant. Necesitamos decidir el modelo de aislamiento de datos entre clientes:

- **Pool**: todos los tenants comparten DB y schema, columna `company_id` en cada tabla.
- **Bridge**: misma DB, schema separado por tenant.
- **Silo**: DB completa por tenant.

Restricciones:

- Costo bajo para tenants pequeños (no podemos darle una DB dedicada a cada Free/Starter).
- Aislamiento estricto verificable (tenants no deben ver datos de otros bajo ninguna circunstancia).
- Performance predecible.
- Capacidad de escalar a 10K+ tenants.
- Posibilidad de ofrecer instancias dedicadas a clientes Enterprise por compliance o tamaño.

## Decisión

Adoptamos un modelo **híbrido**:

1. **Plan default (Free/Starter/Business): Pool**.
   - Todos los tenants en una sola DB y schema `public`.
   - Cada tabla tenant-aware tiene `company_id BIGINT NOT NULL`.
   - Aislamiento garantizado por **tres capas**:
     - Global Query Scope en Eloquent (filtro automático en cada query).
     - Middleware que establece `company_id` en `current_setting('app.current_tenant_id')` por sesión de Postgres.
     - **Row Level Security (RLS) en Postgres** (ver ADR-0006).
2. **Plan Enterprise opcional: Silo**.
   - Cluster de Postgres dedicado.
   - Mismo código, mismo schema, distinto endpoint.
   - Activado a petición del cliente (compliance, tamaño extremo, requisitos regulatorios).

Nunca usaremos Bridge (schema-per-tenant): no escala bien en Postgres y complica migraciones.

## Consecuencias

### Positivas

- Costo marginal por tenant cercano a cero hasta los miles de tenants.
- Aislamiento de tres capas hace muy difícil filtrar datos accidentalmente.
- Mismo código sirve a todos los tenants; un único deploy.
- Tenants Enterprise obtienen aislamiento físico cuando lo necesitan, sin reescritura.

### Negativas

- Queries siempre tienen un filtro extra; ligeramente más caras (mitigado con índices).
- Una migración pesada afecta a todos los tenants; ventana de mantenimiento global.
- Riesgo si un developer olvida agregar `company_id` en una nueva tabla (mitigado con tests de aislamiento obligatorios y revisión de PRs).

## Alternativas consideradas

### Solo Pool

- Más simple, mismo código en todos lados.
- **Descartado**: clientes grandes o regulados (sector salud, gobierno) van a exigir aislamiento físico.

### Solo Silo

- Aislamiento ideal.
- **Descartado**: imposible de costear para tenants pequeños, multiplica complejidad operacional (miles de DBs).

### Bridge (schema por tenant)

- **Descartado**: Postgres no maneja bien decenas de miles de schemas, migraciones se vuelven una pesadilla, conexiones físicas explotan.

## Referencias

- Documento maestro, sección 45 (Estrategia multitenant).
- ADR-0006 (RLS Postgres como defense-in-depth).
