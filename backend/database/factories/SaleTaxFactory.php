<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleTax;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SaleTax>
 */
class SaleTaxFactory extends Factory
{
    protected $model = SaleTax::class;

    public function definition(): array
    {
        $companyId = TenantContext::has() ? TenantContext::id() : null;

        return [
            'company_id' => $companyId ?? Company::factory(),
            'sale_id' => Sale::factory(),
            'code' => 'IVA-16',
            'name' => 'IVA 16%',
            'rate' => 0.16,
            'taxable_base' => 100,
            'amount' => 16,
        ];
    }
}
