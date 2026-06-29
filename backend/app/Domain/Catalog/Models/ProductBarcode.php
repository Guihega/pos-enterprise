<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ProductBarcodeFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Código de barras asociado a un producto.
 *
 * No usa SoftDeletes intencionalmente: cuando un código deja de aplicar,
 * se borra duro. La razón: el unique constraint sobre (company_id, barcode)
 * impediría asignar un mismo código a un producto distinto si quedara como
 * soft-deleted.
 *
 * @property int $id
 * @property int $company_id
 * @property int $product_id
 * @property string $barcode
 * @property string $type
 * @property bool $is_primary
 * @property float $pack_quantity
 */
class ProductBarcode extends Model
{
    use BelongsToTenant;
    use HasFactory;

    public const TYPE_EAN_13 = 'ean-13';

    public const TYPE_EAN_8 = 'ean-8';

    public const TYPE_UPC_A = 'upc-a';

    public const TYPE_UPC_E = 'upc-e';

    public const TYPE_CODE_128 = 'code-128';

    public const TYPE_CODE_39 = 'code-39';

    public const TYPE_QR = 'qr';

    public const TYPE_CUSTOM = 'custom';

    protected $table = 'product_barcodes';

    protected $fillable = [
        'company_id',
        'product_id',
        'barcode',
        'type',
        'is_primary',
        'pack_quantity',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'pack_quantity' => 'decimal:4',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected static function newFactory(): Factory
    {
        return ProductBarcodeFactory::new();
    }
}
