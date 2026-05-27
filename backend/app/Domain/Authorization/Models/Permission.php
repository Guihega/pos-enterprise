<?php

declare(strict_types=1);

namespace App\Domain\Authorization\Models;

use App\Models\Concerns\BelongsToTenant;
use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * Permission específico de POS Enterprise.
 *
 * NOTA sobre permisos globales:
 *   - Si company_id es NULL, el permiso es global (compartido entre
 *     todos los tenants). Útil para permisos del sistema (ej:
 *     "view-system-stats" para super-admin de Anthropic).
 *   - El trait BelongsToTenant rechaza creating/updating con company_id
 *     != tenant en contexto. Para crear permisos globales, hay que estar
 *     en superAdminMode.
 */
class Permission extends SpatiePermission
{
    use BelongsToTenant;

    protected $fillable = [
        'name',
        'guard_name',
        'company_id',
    ];
}
