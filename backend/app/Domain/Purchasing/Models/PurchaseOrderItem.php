<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Models;

use App\Domain\Catalog\Models\Product;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Linea de una orden de compra.
 *
 * No tiene uuid ni deleted_at: no es entidad de ruta y cuelga de la OC con
 * cascadeOnDelete, siguiendo el schema del maestro (26.6).
 *
 * @property int $id
 * @property int $company_id
 * @property int $purchase_order_id
 * @property int $product_id
 * @property float $quantity
 * @property float $quantity_received
 * @property float $unit_cost
 * @property float $tax_rate
 * @property float $tax_amount
 * @property float $subtotal
 * @property float $total
 */
class PurchaseOrderItem extends Model
{
    use BelongsToTenant;

    protected $table = 'purchase_order_items';

    protected $fillable = [
        'company_id',
        'purchase_order_id',
        'product_id',
        'quantity',
        'quantity_received',
        'unit_cost',
        'tax_rate',
        'tax_amount',
        'subtotal',
        'total',
    ];

    protected $casts = [
        'quantity' => 'float',
        'quantity_received' => 'float',
        'unit_cost' => 'float',
        'tax_rate' => 'float',
        'tax_amount' => 'float',
        'subtotal' => 'float',
        'total' => 'float',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
