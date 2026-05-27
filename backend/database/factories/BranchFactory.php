<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'code' => strtoupper(Str::random(4)),
            'name' => $this->faker->randomElement(['Centro', 'Plaza Norte', 'Sur', 'Aeropuerto'])
                .' '.$this->faker->city(),
            'series' => 'A',
            'country_code' => 'MX',
            'state' => $this->faker->state(),
            'city' => $this->faker->city(),
            'postal_code' => $this->faker->postcode(),
            'address' => $this->faker->streetAddress(),
            'phone' => $this->faker->phoneNumber(),
            'email' => $this->faker->companyEmail(),
            'timezone' => 'America/Mexico_City',
            'settings' => [],
            'is_active' => true,
            'is_default' => false,
        ];
    }

    public function default(): self
    {
        return $this->state(fn () => ['is_default' => true]);
    }

    public function inactive(): self
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
