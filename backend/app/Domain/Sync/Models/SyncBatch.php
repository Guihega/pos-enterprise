<?php

declare(strict_types=1);

namespace App\Domain\Sync\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Batch de sync recibido (doc maestro 26.12 sync_batches).
 * uuid es la idempotency key del contrato 38.3.
 */
class SyncBatch extends Model
{
    use BelongsToTenant;

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const UPDATED_AT = null;

    public const CREATED_AT = 'received_at';

    protected $table = 'sync_batches';

    protected $fillable = [
        'uuid', 'company_id', 'device_id', 'branch_id',
        'operations_count', 'success_count', 'conflict_count', 'error_count',
        'status', 'received_at', 'completed_at',
        'request_payload', 'response_payload', 'error_message',
    ];

    protected $casts = [
        'received_at' => 'datetime',
        'completed_at' => 'datetime',
        'request_payload' => 'array',
        'response_payload' => 'array',
    ];

    public function operations(): HasMany
    {
        return $this->hasMany(SyncOperation::class, 'batch_id');
    }
}
