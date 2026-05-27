<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Cash\Models\CashRegister;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CashRegister>
 */
class CashRegisterFactory extends Factory
{
    protected $model = CashRegister::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'branch_id' => Branch::factory(),
            'code' => 'CAJA-'.strtoupper(Str::random(6)),
            'name' => 'Caja '.$this->faker->numberBetween(1, 99),
            'description' => null,
            'is_active' => true,
        ];
    }

    public function ofBranch(Branch $branch): self
    {
        return $this->state(fn () => [
            'branch_id' => $branch->id,
            'company_id' => $branch->company_id,
        ]);
    }
}
