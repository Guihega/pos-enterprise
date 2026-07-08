<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Catalog\Models\Product;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\TransferRequestItemFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Linea de una solicitud de transferencia (CU-GER-003).
 *
 * quantity: cantidad SOLICITADA. Las cantidades enviadas/recibidas viven
 * en transfer_items del Transfer que se crea al aprobar la solicitud.
 *
 * @property int $id
 * @property int $company_id
 * @property int $transfer_request_id
 * @property int $product_id
 * @property float $quantity
 * @property string|null $notes
 */
class TransferRequestItem extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'transfer_request_items';

    protected $fillable = [
        'company_id',
        'transfer_request_id',
        'product_id',
        'quantity',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
    ];

    // -------------------- Relations --------------------

    public function transferRequest(): BelongsTo
    {
        return $this->belongsTo(TransferRequest::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected static function newFactory(): Factory
    {
        return TransferRequestItemFactory::new();
    }
}
