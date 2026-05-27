<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Scopes;

use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Aplica automáticamente WHERE company_id = :current_tenant_id a cada query
 * de un modelo TenantScopedModel.
 *
 * Es la PRIMERA barrera de aislamiento. La segunda es RLS en Postgres
 * (ver ADR-0006). Si por algún motivo este scope se desactiva
 * (`withoutGlobalScopes()`, etc.), RLS sigue protegiendo los datos.
 *
 * Comportamiento si NO hay contexto de tenant:
 *   - En producción: query devuelve 0 filas (fail-secure).
 *   - En tests / consola: lanza para facilitar diagnóstico.
 *
 * Para escenarios legítimos sin tenant (super_admin, jobs cross-tenant),
 * se debe usar TenantContext::enableSuperAdminMode() o el modelo
 * directamente con `withoutGlobalScope(TenantScope::class)`.
 */
final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        // Super-admin bypass: confiamos en RLS, no aplicamos filtro Eloquent.
        if (TenantContext::isSuperAdmin()) {
            return;
        }

        // Sin contexto + ambiente productivo → query vacía (fail-secure).
        if (! TenantContext::has()) {
            $builder->whereRaw('FALSE');

            return;
        }

        $builder->where(
            $model->qualifyColumn('company_id'),
            TenantContext::id()
        );
    }
}
