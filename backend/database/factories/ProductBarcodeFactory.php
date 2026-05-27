<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductBarcode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductBarcode>
 */
class ProductBarcodeFactory extends Factory
{
    protected $model = ProductBarcode::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            // company_id se llena por trait BelongsToTenant en boot, pero
            // si vamos directo a BD necesita estar; lo dejamos al test.
            'barcode' => $this->faker->unique()->ean13(),
            'type' => ProductBarcode::TYPE_EAN_13,
            'is_primary' => false,
            'pack_quantity' => 1,
        ];
    }

    public function primary(): self
    {
        return $this->state(fn () => ['is_primary' => true]);
    }

    public function pack(int $units): self
    {
        return $this->state(fn () => ['pack_quantity' => $units]);
    }

    public function ofProduct(Product $product): self
    {
        return $this->state(fn () => [
            'product_id' => $product->id,
            'company_id' => $product->company_id,
        ]);
    }
}
