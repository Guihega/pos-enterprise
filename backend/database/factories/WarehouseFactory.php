<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Warehouse>
 */
class WarehouseFactory extends Factory
{
    protected $model = Warehouse::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'branch_id' => Branch::factory(),
            'code' => 'WH-'.strtoupper(Str::random(6)),
            'name' => 'Almacén '.$this->faker->word(),
            'description' => null,
            'type' => Warehouse::TYPE_MAIN,
            'is_sellable' => true,
            'is_default' => false,
            'is_active' => true,
        ];
    }

    public function default(): self
    {
        return $this->state(fn () => ['is_default' => true]);
    }

    public function storage(): self
    {
        return $this->state(fn () => [
            'type' => Warehouse::TYPE_STORAGE,
            'is_sellable' => false,
        ]);
    }

    public function ofBranch(Branch $branch): self
    {
        return $this->state(fn () => [
            'branch_id' => $branch->id,
            'company_id' => $branch->company_id,
        ]);
    }
}
