<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Inventory\Models\Batch;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lote consumido por una linea de venta (checkout 9c, FEFO RN-045).
 *
 * @property int $id
 * @property int $company_id
 * @property int $sale_item_id
 * @property int $batch_id
 * @property float $quantity
 * @property float $unit_cost
 */
class SaleItemBatch extends Model
{
    use BelongsToTenant;

    protected $table = 'sale_item_batches';

    protected $fillable = [
        'company_id',
        'sale_item_id',
        'batch_id',
        'quantity',
        'unit_cost',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_cost' => 'decimal:4',
    ];

    // -------------------- Relations --------------------

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }
}
