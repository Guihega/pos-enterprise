<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Models\Transfer;
use App\Domain\Inventory\Models\TransferItem;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TransferItem>
 */
class TransferItemFactory extends Factory
{
    protected $model = TransferItem::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'transfer_id' => Transfer::factory(),
            'product_id' => Product::factory(),
            'quantity_sent' => $this->faker->randomFloat(2, 1, 50),
            'quantity_received' => null,
            'unit_cost' => $this->faker->randomFloat(2, 1, 100),
            'notes' => null,
        ];
    }
}
