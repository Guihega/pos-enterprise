<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Models\TenantScopedModel;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Producto del catálogo.
 *
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property int|null $category_id
 * @property int|null $brand_id
 * @property int $unit_id
 * @property int|null $tax_id
 * @property int|null $parent_id
 * @property string $sku
 * @property string $name
 * @property string|null $description
 * @property string|null $short_description
 * @property float $cost
 * @property float $price
 * @property float|null $compare_at_price
 * @property float|null $min_price
 * @property bool $track_inventory
 * @property bool $is_sellable
 * @property bool $is_purchasable
 * @property bool $allow_decimals
 * @property string $status
 * @property array<string, mixed> $custom_attributes
 * @property array<string, mixed> $metadata
 */
class Product extends TenantScopedModel
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    protected $table = 'products';

    protected $fillable = [
        'uuid',
        'company_id',
        'category_id',
        'brand_id',
        'unit_id',
        'tax_id',
        'parent_id',
        'sku',
        'name',
        'description',
        'short_description',
        'cost',
        'price',
        'compare_at_price',
        'min_price',
        'track_inventory',
        'is_sellable',
        'is_purchasable',
        'allow_decimals',
        'status',
        'published_at',
        'weight',
        'weight_unit',
        'dimensions',
        'tax_code',
        'custom_attributes',
        'metadata',
    ];

    protected $casts = [
        'cost' => 'decimal:4',
        'price' => 'decimal:4',
        'compare_at_price' => 'decimal:4',
        'min_price' => 'decimal:4',
        'weight' => 'decimal:4',
        'track_inventory' => 'boolean',
        'is_sellable' => 'boolean',
        'is_purchasable' => 'boolean',
        'allow_decimals' => 'boolean',
        'published_at' => 'datetime',
        'dimensions' => 'array',
        'custom_attributes' => 'array',
        'metadata' => 'array',
    ];

    // -------------------- Relaciones --------------------

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function barcodes(): HasMany
    {
        return $this->hasMany(ProductBarcode::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)
            ->orderBy('sort_order');
    }

    // -------------------- Lógica --------------------

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isVariant(): bool
    {
        return $this->parent_id !== null;
    }

    public function hasDiscount(): bool
    {
        return $this->compare_at_price !== null
            && (float) $this->compare_at_price > (float) $this->price;
    }

    /**
     * Margen bruto: (price - cost) / price * 100
     */
    protected function marginPercent(): Attribute
    {
        return Attribute::get(function (): ?float {
            $price = (float) $this->price;
            if ($price <= 0) {
                return null;
            }

            return round((($price - (float) $this->cost) / $price) * 100, 2);
        });
    }

    // -------------------- Scopes --------------------

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_ACTIVE);
    }

    public function scopeSellable(Builder $q): Builder
    {
        return $q->where('is_sellable', true)->where('status', self::STATUS_ACTIVE);
    }

    public function scopeArchived(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_ARCHIVED);
    }

    public function scopeDraft(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_DRAFT);
    }

    /**
     * Búsqueda por nombre, SKU o barcode (vía join).
     * Usa ILIKE para case-insensitive en Postgres + GIN trigram (ya tiene índice).
     */
    public function scopeSearch(Builder $q, string $term): Builder
    {
        $term = trim($term);
        if ($term === '') {
            return $q;
        }

        return $q->where(function (Builder $sub) use ($term): void {
            $sub->where('name', 'ilike', "%{$term}%")
                ->orWhere('sku', 'ilike', "{$term}%")
                ->orWhereHas('barcodes', fn (Builder $b) => $b->where('barcode', $term));
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function newFactory(): Factory
    {
        return ProductFactory::new();
    }
}
