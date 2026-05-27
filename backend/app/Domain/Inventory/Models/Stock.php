<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Catalog\Models\Product;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\StockFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stock por producto y almacén.
 *
 * Un registro por cada combinación (product_id, warehouse_id). No usa
 * SoftDeletes: si un producto deja de existir en un almacén, el registro
 * se borra duro (cascadeOnDelete) o queda con quantity 0.
 *
 * @property int $id
 * @property int $company_id
 * @property int $product_id
 * @property int $warehouse_id
 * @property float $quantity_on_hand
 * @property float $quantity_reserved
 * @property float|null $stock_min
 * @property float|null $stock_max
 * @property float $average_cost
 * @property \Carbon\Carbon|null $last_movement_at
 *
 * @property-read float $quantity_available
 */
class Stock extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'stocks';

    protected $fillable = [
        'company_id',
        'product_id',
        'warehouse_id',
        'quantity_on_hand',
        'quantity_reserved',
        'stock_min',
        'stock_max',
        'average_cost',
        'last_movement_at',
    ];

    protected $casts = [
        'quantity_on_hand' => 'decimal:4',
        'quantity_reserved' => 'decimal:4',
        'stock_min' => 'decimal:4',
        'stock_max' => 'decimal:4',
        'average_cost' => 'decimal:4',
        'last_movement_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * Cantidad disponible para vender = on_hand - reserved
     */
    protected function quantityAvailable(): Attribute
    {
        return Attribute::get(fn (): float => max(
            0,
            (float) $this->quantity_on_hand - (float) $this->quantity_reserved
        ));
    }

    /**
     * ¿El stock está bajo el mínimo?
     */
    public function isLowStock(): bool
    {
        if ($this->stock_min === null) {
            return false;
        }

        return (float) $this->quantity_on_hand <= (float) $this->stock_min;
    }

    /**
     * ¿El stock excede el máximo configurado?
     */
    public function isOverstock(): bool
    {
        if ($this->stock_max === null) {
            return false;
        }

        return (float) $this->quantity_on_hand >= (float) $this->stock_max;
    }

    protected static function newFactory(): Factory
    {
        return StockFactory::new();
    }
}
