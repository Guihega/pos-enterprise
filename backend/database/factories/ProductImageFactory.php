<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductImage>
 */
class ProductImageFactory extends Factory
{
    protected $model = ProductImage::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'product_id' => Product::factory(),
            'url' => $this->faker->imageUrl(800, 800),
            'thumbnail_url' => $this->faker->imageUrl(200, 200),
            'alt_text' => $this->faker->sentence(3),
            'mime_type' => 'image/jpeg',
            'size_bytes' => $this->faker->numberBetween(50000, 500000),
            'sort_order' => 0,
            'is_primary' => false,
        ];
    }

    public function primary(): self
    {
        return $this->state(fn () => ['is_primary' => true]);
    }

    public function ofProduct(Product $product): self
    {
        return $this->state(fn () => [
            'product_id' => $product->id,
            'company_id' => $product->company_id,
        ]);
    }
}
