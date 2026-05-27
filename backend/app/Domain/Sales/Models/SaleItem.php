<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Catalog\Models\Product;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\SaleItemFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property int $sale_id
 * @property int $product_id
 * @property string $product_sku
 * @property string $product_name
 * @property string|null $unit_name
 * @property float $quantity
 * @property float $unit_price
 * @property float $unit_cost
 * @property float $line_subtotal
 * @property float $discount_percent
 * @property float $discount_amount
 * @property bool $is_taxable
 * @property bool $tax_inclusive
 * @property float $tax_rate
 * @property float $tax_amount
 * @property string|null $tax_code
 * @property float $line_total
 * @property bool $track_inventory
 * @property array<string, mixed> $metadata
 */
class SaleItem extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'sale_items';

    protected $fillable = [
        'uuid', 'company_id', 'sale_id', 'product_id',
        'product_sku', 'product_name', 'unit_name',
        'quantity', 'unit_price', 'unit_cost',
        'line_subtotal', 'discount_percent', 'discount_amount',
        'is_taxable', 'tax_inclusive', 'tax_rate', 'tax_amount', 'tax_code',
        'line_total', 'track_inventory',
        'metadata',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'line_subtotal' => 'decimal:2',
        'discount_percent' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'is_taxable' => 'boolean',
        'tax_inclusive' => 'boolean',
        'tax_rate' => 'decimal:6',
        'tax_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
        'track_inventory' => 'boolean',
        'metadata' => 'array',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected static function newFactory(): Factory
    {
        return SaleItemFactory::new();
    }
}
