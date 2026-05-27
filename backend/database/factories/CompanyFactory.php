<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        $name = $this->faker->company();

        return [
            'uuid' => (string) Str::uuid(),
            'slug' => Str::slug($name).'-'.Str::random(4),
            'name' => $name,
            'legal_name' => $name.' S.A. de C.V.',
            'tax_id' => strtoupper($this->faker->bothify('???######??#')),
            'country_code' => 'MX',
            'currency_code' => 'MXN',
            'timezone' => 'America/Mexico_City',
            'locale' => 'es_MX',
            'plan' => Company::PLAN_STARTER,
            'status' => Company::STATUS_ACTIVE,
            'logo_url' => null,
            'primary_color' => '#1e40af',
            'settings' => [],
            'limits' => [
                'branches' => 3,
                'users' => 15,
                'products' => 5000,
            ],
        ];
    }

    public function trial(): self
    {
        return $this->state(fn () => [
            'status' => Company::STATUS_TRIAL,
            'trial_ends_at' => now()->addDays(30),
        ]);
    }

    public function suspended(string $reason = 'Pago vencido'): self
    {
        return $this->state(fn () => [
            'status' => Company::STATUS_SUSPENDED,
            'suspension_reason' => $reason,
            'suspended_at' => now(),
        ]);
    }

    public function plan(string $plan): self
    {
        return $this->state(fn () => ['plan' => $plan]);
    }
}
