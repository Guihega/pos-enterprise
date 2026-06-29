<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Branch;
use App\Models\Concerns\BelongsToTenant;
use Carbon\Carbon;
use Database\Factories\InventoryMovementFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Movimiento de inventario (kardex).
 *
 * INMUTABLE: la BD bloquea UPDATE/DELETE vía trigger. Para "corregir"
 * un movimiento, se inserta otro que lo compense.
 *
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property int $product_id
 * @property int $warehouse_id
 * @property int $branch_id
 * @property string $type
 * @property string|null $source_type
 * @property int|null $source_id
 * @property string|null $transfer_id
 * @property float $quantity_delta
 * @property float $quantity_after
 * @property float $unit_cost
 * @property float $total_cost
 * @property float $average_cost_after
 * @property string|null $reason
 * @property string|null $reference
 * @property int|null $user_id
 * @property array<string, mixed> $metadata
 * @property Carbon $movement_at
 */
class InventoryMovement extends Model
{
    use BelongsToTenant;
    use HasFactory;

    public const TYPE_ENTRY = 'entry';

    public const TYPE_EXIT = 'exit';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPE_TRANSFER_OUT = 'transfer_out';

    public const TYPE_TRANSFER_IN = 'transfer_in';

    public const TYPE_RETURN_CUSTOMER = 'return_customer';

    public const TYPE_RETURN_SUPPLIER = 'return_supplier';

    public const TYPE_PRODUCTION_IN = 'production_in';

    public const TYPE_PRODUCTION_OUT = 'production_out';

    public const TYPE_OPENING = 'opening';

    protected $table = 'inventory_movements';

    protected $fillable = [
        'uuid',
        'company_id',
        'product_id',
        'warehouse_id',
        'branch_id',
        'type',
        'source_type',
        'source_id',
        'transfer_id',
        'quantity_delta',
        'quantity_after',
        'unit_cost',
        'total_cost',
        'average_cost_after',
        'reason',
        'reference',
        'user_id',
        'metadata',
        'movement_at',
    ];

    protected $casts = [
        'quantity_delta' => 'decimal:4',
        'quantity_after' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'total_cost' => 'decimal:4',
        'average_cost_after' => 'decimal:4',
        'metadata' => 'array',
        'movement_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Es entrada (delta positivo)
     */
    public function isEntry(): bool
    {
        return (float) $this->quantity_delta > 0;
    }

    public function isExit(): bool
    {
        return (float) $this->quantity_delta < 0;
    }

    // -------------------- Scopes --------------------

    public function scopeOfProduct(Builder $q, int $productId): Builder
    {
        return $q->where('product_id', $productId);
    }

    public function scopeOfWarehouse(Builder $q, int $warehouseId): Builder
    {
        return $q->where('warehouse_id', $warehouseId);
    }

    public function scopeOfType(Builder $q, string $type): Builder
    {
        return $q->where('type', $type);
    }

    public function scopeBetween(Builder $q, \DateTimeInterface $from, \DateTimeInterface $to): Builder
    {
        return $q->whereBetween('movement_at', [$from, $to]);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function newFactory(): Factory
    {
        return InventoryMovementFactory::new();
    }
}
