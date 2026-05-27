<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Models\InventoryMovement;
use App\Domain\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<InventoryMovement>
 */
class InventoryMovementFactory extends Factory
{
    protected $model = InventoryMovement::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'product_id' => Product::factory(),
            'warehouse_id' => Warehouse::factory(),
            // company_id, branch_id se setean explícitamente desde tests
            'type' => InventoryMovement::TYPE_ENTRY,
            'quantity_delta' => 10,
            'quantity_after' => 10,
            'unit_cost' => 50,
            'total_cost' => 500,
            'average_cost_after' => 50,
            'reason' => null,
            'reference' => null,
            'user_id' => null,
            'metadata' => [],
            'movement_at' => now(),
        ];
    }

    public function entry(float $quantity = 10): self
    {
        return $this->state(fn () => [
            'type' => InventoryMovement::TYPE_ENTRY,
            'quantity_delta' => $quantity,
        ]);
    }

    public function exit(float $quantity = 5): self
    {
        return $this->state(fn () => [
            'type' => InventoryMovement::TYPE_EXIT,
            'quantity_delta' => -abs($quantity),
        ]);
    }

    public function adjustment(float $delta, string $reason): self
    {
        return $this->state(fn () => [
            'type' => InventoryMovement::TYPE_ADJUSTMENT,
            'quantity_delta' => $delta,
            'reason' => $reason,
        ]);
    }
}
