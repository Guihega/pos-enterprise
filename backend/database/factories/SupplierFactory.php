<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Purchasing\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $n = $this->faker->unique()->numberBetween(1, 999999);

        return [
            'uuid' => (string) Str::uuid(),
            'code' => 'PROV-'.$n,
            'name' => 'Proveedor '.$n,
            'contact_name' => null,
            'phone' => null,
            'email' => null,
            'notes' => null,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $a): array => ['is_active' => false]);
    }
}
