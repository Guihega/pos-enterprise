<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Clase base para CUALQUIER modelo tenant-scoped del producto que NO
 * tenga restricciones de herencia (ej: que no necesite extender
 * Authenticatable).
 *
 * La lógica multi-tenant vive en el trait BelongsToTenant para garantizar
 * que User (que sí extiende Authenticatable) tenga exactamente el mismo
 * comportamiento.
 *
 * Convención: cada modelo tenant-aware debe extender ESTA clase, salvo
 * casos especiales (User) donde se aplica el trait directamente.
 */
abstract class TenantScopedModel extends Model
{
    use BelongsToTenant;
    use SoftDeletes;

    /**
     * Por convención todas las entidades públicas usan UUID en URLs.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
