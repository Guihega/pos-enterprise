<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Customer\Models\Customer;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            // Si hay TenantContext, lo usamos; si no, creamos un Company nuevo.
            // Esto evita el bug de "Company::factory() crea tenant nuevo" cuando
            // el test ya tiene un tenant en contexto.
            'company_id' => TenantContext::has()
                ? TenantContext::id()
                : Company::factory(),
            'code' => null,
            'type' => Customer::TYPE_INDIVIDUAL,
            'name' => $this->faker->name(),
            'legal_name' => null,
            'tax_id' => null,
            'tax_data' => [],
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => $this->faker->phoneNumber(),
            'mobile' => null,
            'address_line' => $this->faker->streetAddress(),
            'city' => $this->faker->city(),
            'state' => $this->faker->state(),
            'postal_code' => $this->faker->postcode(),
            'country_code' => 'MX',
            'credit_limit' => 0,
            'credit_balance' => 0,
            'is_active' => true,
            'is_blocked' => false,
            'notes' => null,
        ];
    }

    public function business(): self
    {
        return $this->state(function (): array {
            return [
                'type' => Customer::TYPE_BUSINESS,
                'name' => $this->faker->company(),
                'legal_name' => $this->faker->company().' S.A. de C.V.',
                'tax_id' => strtoupper(Str::random(12)),
            ];
        });
    }

    public function withCredit(float $limit = 10000): self
    {
        return $this->state(fn () => ['credit_limit' => $limit]);
    }

    public function blocked(string $reason = 'Deuda vencida'): self
    {
        return $this->state(fn () => [
            'is_blocked' => true,
            'blocked_reason' => $reason,
        ]);
    }
}
