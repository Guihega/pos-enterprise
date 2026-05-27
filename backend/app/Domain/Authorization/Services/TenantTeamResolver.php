<?php

declare(strict_types=1);

namespace App\Domain\Authorization\Services;

use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Contracts\PermissionsTeamResolver;

/**
 * Resolver de "team_id" para Spatie Permission.
 *
 * Conecta el sistema de permisos con nuestro multi-tenant: el team_id
 * que Spatie usa para filtrar roles/permisos es el company_id en
 * TenantContext.
 *
 * NOTA sobre la firma: la interface PermissionsTeamResolver de Spatie
 * v7 declara el parámetro $id SIN tipos:
 *
 *   public function setPermissionsTeamId($id): void;
 *
 * Mantenemos esa firma idéntica para no romper la compatibilidad de
 * herencia. Internamente convertimos a int|string|null como hace el
 * DefaultTeamResolver.
 */
final class TenantTeamResolver implements PermissionsTeamResolver
{
    private int|string|null $override = null;

    public function setPermissionsTeamId($id): void
    {
        if ($id instanceof Model) {
            $id = $id->getKey();
        }
        $this->override = $id;
    }

    public function getPermissionsTeamId(): int|string|null
    {
        if ($this->override !== null) {
            return $this->override;
        }

        return TenantContext::has() ? TenantContext::id() : null;
    }
}
