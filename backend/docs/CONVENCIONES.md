# Convenciones del proyecto — Migraciones y Schema

Este archivo recoge convenciones aprendidas durante la construcción de
Fase 1 que NO están en docs estándar de Laravel y que rompen cosas si
no se respetan.

## Antes de generar/modificar una migración

1. **Verificar el archivo en disco**: `cat database/migrations/<nombre>.php`
   antes de sobrescribir. Si existe contenido distinto, decidir
   conscientemente qué hacer (reemplazar, extender con migración delta,
   conservar lo existente).

2. **No nombrar columnas con palabras reservadas de Eloquent**. La columna
   `attributes` choca con la propiedad mágica `$model->attributes` que
   guarda el array de columnas. Acceder al JSON termina devolviendo el
   array completo de Eloquent → bug sutil. Nombres seguros:
   `custom_attributes`, `extra`, `meta`, `props`, `traits_data`.

3. **Toda tabla tenant-scoped DEBE tener** `company_id` + RLS:
   ```php
   TenantTable::companyColumn($table);
   // ...
   TenantTable::enableRls('mi_tabla');
   ```

4. **Verificar columnas que crean otras llamadas mágicas** antes de
   añadir más:
   - `morphs()` → crea `*_id` y `*_type`
   - `softDeletesTz()` → crea `deleted_at`
   - `rememberToken()` → crea `remember_token`
   - `timestampsTz()` → crea `created_at`, `updated_at`

## Tests

1. **Helpers globales van a `tests/Pest.php`** envueltos con
   `if (! function_exists())`. Los archivos `*Test.php` solo deben tener
   `it()`, `beforeEach()`, `afterEach()`.

2. **Tests que esperan QueryException por constraint violations** DEBEN
   usar el helper `expectQueryException()` (que envuelve la query en
   `DB::transaction()`). Postgres aborta la transacción exterior si una
   query falla y el tearDown rompe.

3. **Tests multi-tenant SIEMPRE pasan `company_id` explícito** a las
   factories y SIEMPRE hacen `TenantContext::set()` antes de crear en
   otro tenant.

4. **Schema sentinels**: cuando un schema diverge en el repo (ej. dos
   migraciones del mismo nombre, una vieja una nueva), agregar tests
   sentinel que verifiquen `information_schema.columns` para detectar
   regresión inmediata. Ver `ProductModelTest.php` "schema sentinel".

## Paquetes con upgrade major

Antes de asumir API previa de un paquete major-bumped (Sanctum 3→4,
Spatie v6→v7, etc.), buscar el `UPGRADE.md` oficial y leer **el contrato/
interface real dentro de `vendor/`**, no las implementaciones de ejemplo
en docs (que pueden tener tipos extras).

## Pivots M2M con NOT NULL extra

Pivots con columnas adicionales NOT NULL requieren un método helper
`syncX()` en el modelo. `Eloquent::sync()` no llena cols extra.
