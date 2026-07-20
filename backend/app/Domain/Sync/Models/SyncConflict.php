<?php

declare(strict_types=1);

namespace App\Domain\Sync\Models;

use App\Domain\Identity\Models\User;
use App\Models\Concerns\BelongsToTenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Conflicto de sync pendiente de resolucion humana (maestro 26.12,
 * sec. 39.3). resolved_at NULL => pendiente. Valores de resolution
 * catalogados en el DDL: accept_client, accept_server, manual_merge.
 * Solo gerente o admin resuelve (39.3); auditoria via activity_log
 * DIFERIDA (RN-170, tabla inexistente en el proyecto).
 *
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property int $branch_id
 * @property string|null $device_id
 * @property int|null $sync_operation_id
 * @property string $entity_type
 * @property string $entity_uuid
 * @property string $conflict_type
 * @property array $client_data
 * @property array $server_data
 * @property string|null $resolution
 * @property Carbon|null $resolved_at
 * @property int|null $resolved_by
 * @property string|null $notes
 */
class SyncConflict extends Model
{
    use BelongsToTenant;

    public const TYPE_CASH_SESSION_CLOSED = 'cash_session_closed';

    public const TYPE_PRICE_MISMATCH = 'price_mismatch';

    public const RESOLUTION_ACCEPT_CLIENT = 'accept_client';

    public const RESOLUTION_ACCEPT_SERVER = 'accept_server';

    public const RESOLUTION_MANUAL_MERGE = 'manual_merge';

    public const RESOLUTIONS = [
        self::RESOLUTION_ACCEPT_CLIENT,
        self::RESOLUTION_ACCEPT_SERVER,
        self::RESOLUTION_MANUAL_MERGE,
    ];

    public $timestamps = false;

    protected $table = 'sync_conflicts';

    protected $fillable = [
        'uuid', 'company_id', 'branch_id', 'device_id',
        'sync_operation_id', 'entity_type', 'entity_uuid',
        'conflict_type', 'client_data', 'server_data',
        'resolution', 'resolved_at', 'resolved_by', 'notes',
    ];

    protected $casts = [
        'client_data' => 'array',
        'server_data' => 'array',
        'resolved_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }
}
