<?php

declare(strict_types=1);

namespace App\Domain\Authorization\Models;

use App\Models\Concerns\BelongsToTenant;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Role específico de POS Enterprise.
 *
 * Extiende el Role de Spatie y le aplica el trait BelongsToTenant para que:
 *   - El TenantScope filtre automáticamente queries de Eloquent.
 *   - La validación de creating/updating bloquee intentos cross-tenant.
 *
 * IMPORTANTE: Spatie ya filtra por team_id (company_id) en sus queries
 * internas. Nuestro scope es una segunda barrera, redundante pero deseable
 * (defense-in-depth + facilita queries directas por código de aplicación).
 */
class Role extends SpatieRole
{
    use BelongsToTenant;

    /**
     * Spatie usa $guarded por defecto. Habilitamos $fillable para que
     * las factories y servicios puedan crear roles con campos seguros.
     */
    protected $fillable = [
        'name',
        'guard_name',
        'company_id',
    ];
}
