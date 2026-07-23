<?php

declare(strict_types=1);

namespace App\Domain\Audit\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Registro de auditoria (maestro 26.13, RN-170).
 *
 * Inmutable (RN-171): la BD aborta UPDATE/DELETE via trigger
 * (migracion 000043). El modelo no expone updated_at y solo debe
 * crearse a traves de ActivityLogger.
 */
final class ActivityLog extends Model
{
    use BelongsToTenant;

    protected $table = 'activity_log';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'properties' => 'array',
        'created_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
