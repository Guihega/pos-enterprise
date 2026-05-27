<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Models\Stock;
use App\Domain\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Stock>
 */
class StockFactory extends Factory
{
    protected $model = Stock::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'warehouse_id' => Warehouse::factory(),
            // company_id se rellena por el trait BelongsToTenant cuando hay contexto
            'quantity_on_hand' => $this->faker->randomFloat(2, 0, 200),
            'quantity_reserved' => 0,
            'stock_min' => null,
            'stock_max' => null,
            'average_cost' => $this->faker->randomFloat(2, 1, 500),
            'last_movement_at' => null,
        ];
    }

    public function ofProduct(Product $product, Warehouse $warehouse): self
    {
        return $this->state(fn () => [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'company_id' => $product->company_id,
        ]);
    }

    public function withQuantity(float $onHand, float $reserved = 0): self
    {
        return $this->state(fn () => [
            'quantity_on_hand' => $onHand,
            'quantity_reserved' => $reserved,
        ]);
    }
}
