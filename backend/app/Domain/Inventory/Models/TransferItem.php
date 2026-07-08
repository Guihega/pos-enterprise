<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Catalog\Models\Product;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\TransferItemFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Linea de una transferencia inter-sucursal (doc maestro 46.4).
 *
 * quantity_sent: cantidad despachada (descuenta origen al pasar a 'sent').
 * quantity_received: confirmada al recibir; NULL hasta la recepcion.
 * Merma => quantity_sent - quantity_received => ajuste transfer_loss (RN-049).
 *
 * @property int $id
 * @property int $company_id
 * @property int $transfer_id
 * @property int $product_id
 * @property float $quantity_sent
 * @property float|null $quantity_received
 * @property float $unit_cost
 * @property string|null $notes
 */
class TransferItem extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'transfer_items';

    protected $fillable = [
        'company_id',
        'transfer_id',
        'product_id',
        'quantity_sent',
        'quantity_received',
        'unit_cost',
        'notes',
    ];

    protected $casts = [
        'quantity_sent' => 'decimal:4',
        'quantity_received' => 'decimal:4',
        'unit_cost' => 'decimal:4',
    ];

    // -------------------- Relations --------------------

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected static function newFactory(): Factory
    {
        return TransferItemFactory::new();
    }
}
