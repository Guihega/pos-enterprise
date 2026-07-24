<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cabecera de devolucion (CU-CAJ-010). Sin soft deletes ni updated_at:
 * registro historico inmutable a nivel de aplicacion; trait
 * BelongsToTenant directo (patron SyncConflict/ActivityLog).
 */
class SaleReturn extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'total_amount' => 'decimal:4',
        'cash_refunded' => 'decimal:4',
        'created_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class);
    }
}
