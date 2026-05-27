<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SalePayment;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SalePayment>
 */
class SalePaymentFactory extends Factory
{
    protected $model = SalePayment::class;

    public function definition(): array
    {
        $companyId = TenantContext::has() ? TenantContext::id() : null;

        return [
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId ?? Company::factory(),
            'sale_id' => Sale::factory(),
            'method' => SalePayment::METHOD_CASH,
            'amount' => 100,
            'tendered_amount' => 100,
            'metadata' => [],
            'captured_at' => now(),
        ];
    }

    public function cash(float $amount = 100, ?float $tendered = null): self
    {
        return $this->state(fn () => [
            'method' => SalePayment::METHOD_CASH,
            'amount' => $amount,
            'tendered_amount' => $tendered ?? $amount,
        ]);
    }

    public function card(float $amount = 100, string $brand = 'visa'): self
    {
        return $this->state(fn () => [
            'method' => SalePayment::METHOD_CARD_DEBIT,
            'amount' => $amount,
            'tendered_amount' => null,
            'card_brand' => $brand,
            'card_last4' => (string) random_int(1000, 9999),
            'authorization_code' => strtoupper(Str::random(6)),
        ]);
    }
}
