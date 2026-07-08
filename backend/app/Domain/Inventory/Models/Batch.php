<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Tenancy\Models\Branch;
use App\Models\Concerns\BelongsToTenant;
use Carbon\Carbon;
use Database\Factories\BatchFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lote de producto (doc maestro 26.x product_batches, glosario Batch/Lot).
 *
 * Subdivision de un producto por origen, fecha de recepcion y caducidad.
 * expiration_date nullable (RN-046: lote sin caducidad permitido).
 * quantity es el remanente vivo; received_quantity lo recibido original.
 * FEFO (RN-045): consumir primero el lote con expiration_date mas proxima.
 *
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property int $product_id
 * @property int $branch_id
 * @property int|null $warehouse_id
 * @property string|null $lot_number
 * @property Carbon|null $expiration_date
 * @property Carbon $received_date
 * @property float $received_quantity
 * @property float $quantity
 * @property float $cost
 * @property string|null $notes
 */
class Batch extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'product_batches';

    protected $fillable = [
        'uuid', 'company_id',
        'product_id', 'branch_id', 'warehouse_id',
        'lot_number', 'expiration_date',
        'received_date', 'received_quantity', 'quantity', 'cost',
        'notes',
    ];

    protected $casts = [
        'expiration_date' => 'date',
        'received_date' => 'date',
        'received_quantity' => 'decimal:3',
        'quantity' => 'decimal:3',
        'cost' => 'decimal:4',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    // -------------------- Relations --------------------

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    // -------------------- Scopes --------------------

    /** Lotes con remanente (alineado al indice parcial FEFO). */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('quantity', '>', 0);
    }

    /** Orden FEFO (RN-045): caducidad mas proxima primero; sin caducidad al final. */
    public function scopeFefo(Builder $query): Builder
    {
        return $query->orderByRaw('expiration_date ASC NULLS LAST')->orderBy('received_date');
    }

    // -------------------- Helpers --------------------

    public function isExpired(): bool
    {
        return $this->expiration_date !== null && $this->expiration_date->isPast();
    }

    protected static function newFactory(): Factory
    {
        return BatchFactory::new();
    }
}
