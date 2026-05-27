<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ProductImageFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Imagen de producto.
 *
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property int $product_id
 * @property string $url
 * @property string|null $thumbnail_url
 * @property string|null $alt_text
 * @property string|null $mime_type
 * @property int|null $size_bytes
 * @property int $sort_order
 * @property bool $is_primary
 */
class ProductImage extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'product_images';

    protected $fillable = [
        'uuid',
        'company_id',
        'product_id',
        'url',
        'thumbnail_url',
        'alt_text',
        'mime_type',
        'size_bytes',
        'sort_order',
        'is_primary',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'sort_order' => 'integer',
        'size_bytes' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function newFactory(): Factory
    {
        return ProductImageFactory::new();
    }
}
