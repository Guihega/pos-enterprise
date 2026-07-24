<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Renglon de devolucion. Sin company_id: acceso via cabecera (la
 * migracion 000044 documenta el patron). Sin timestamps.
 */
class SaleReturnItem extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'quantity' => 'decimal:4',
        'amount' => 'decimal:4',
    ];

    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class);
    }
}
