<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Models\Batch;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Batch>
 */
class BatchFactory extends Factory
{
    protected $model = Batch::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'product_id' => Product::factory(),
            'branch_id' => Branch::factory(),
            'warehouse_id' => null,
            'lot_number' => strtoupper($this->faker->bothify('LOT-####??')),
            'expiration_date' => now()->addMonths(6)->toDateString(),
            'received_date' => now()->toDateString(),
            'received_quantity' => 10,
            'quantity' => 10,
            'cost' => 0,
            'notes' => null,
        ];
    }

    public function expired(): self
    {
        return $this->state(fn () => [
            'expiration_date' => now()->subDay()->toDateString(),
        ]);
    }

    public function withoutExpiration(): self
    {
        return $this->state(fn () => [
            'expiration_date' => null,
        ]);
    }

    public function depleted(): self
    {
        return $this->state(fn () => [
            'quantity' => 0,
        ]);
    }
}
