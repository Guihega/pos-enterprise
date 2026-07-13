<?php

declare(strict_types=1);

namespace App\Domain\Sync\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Operacion individual dentro de un sync batch (doc maestro 26.12).
 * Sin company_id ni RLS propio (fiel al DDL): el acceso es via batch,
 * que es la frontera tenant.
 */
class SyncOperation extends Model
{
    public $timestamps = false;

    protected $table = 'sync_operations';

    protected $fillable = [
        'batch_id', 'client_uuid', 'entity_type', 'entity_uuid',
        'operation', 'client_timestamp', 'payload',
        'status', 'server_id', 'server_uuid', 'response',
        'error_code', 'error_message',
    ];

    protected $casts = [
        'client_timestamp' => 'datetime',
        'payload' => 'array',
        'response' => 'array',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(SyncBatch::class, 'batch_id');
    }
}
