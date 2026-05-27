<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Tax;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $price = $this->faker->randomFloat(2, 5, 1000);
        $cost = $price * $this->faker->randomFloat(2, 0.4, 0.7);

        return [
            'uuid' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'category_id' => null,
            'brand_id' => null,
            'unit_id' => Unit::factory(),
            'tax_id' => null,
            'parent_id' => null,
            'sku' => 'SKU-'.strtoupper(Str::random(8)),
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->optional()->paragraph(),
            'short_description' => $this->faker->optional()->sentence(),
            'cost' => $cost,
            'price' => $price,
            'compare_at_price' => null,
            'min_price' => null,
            'track_inventory' => true,
            'is_sellable' => true,
            'is_purchasable' => true,
            'allow_decimals' => false,
            'status' => Product::STATUS_ACTIVE,
            'published_at' => now(),
            'custom_attributes' => [],
            'metadata' => [],
        ];
    }

    public function active(): self
    {
        return $this->state(fn () => ['status' => Product::STATUS_ACTIVE]);
    }

    public function draft(): self
    {
        return $this->state(fn () => ['status' => Product::STATUS_DRAFT, 'published_at' => null]);
    }

    public function archived(): self
    {
        return $this->state(fn () => ['status' => Product::STATUS_ARCHIVED]);
    }

    public function notSellable(): self
    {
        return $this->state(fn () => ['is_sellable' => false]);
    }

    public function withDiscount(float $percent = 20): self
    {
        return $this->state(function (array $attrs) use ($percent) {
            $price = (float) $attrs['price'];
            $compare = round($price / (1 - $percent / 100), 2);

            return ['compare_at_price' => $compare];
        });
    }

    public function inCategory(Category $category): self
    {
        return $this->state(fn () => [
            'category_id' => $category->id,
            'company_id' => $category->company_id,
        ]);
    }

    public function ofBrand(Brand $brand): self
    {
        return $this->state(fn () => [
            'brand_id' => $brand->id,
            'company_id' => $brand->company_id,
        ]);
    }

    public function withTax(Tax $tax): self
    {
        return $this->state(fn () => [
            'tax_id' => $tax->id,
            'company_id' => $tax->company_id,
        ]);
    }
}
