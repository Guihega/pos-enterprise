<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Cash\Models\CashRegister;
use App\Domain\Cash\Models\CashSession;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Sales\Models\Sale;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Sale>
 */
class SaleFactory extends Factory
{
    protected $model = Sale::class;

    public function definition(): array
    {
        $companyId = TenantContext::has() ? TenantContext::id() : null;

        return [
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId ?? Company::factory(),
            'number' => 'TMP-'.strtoupper(Str::random(8)),
            'series' => 'A',
            'number_value' => $this->faker->numberBetween(1, 999999),
            'branch_id' => Branch::factory(),
            'cash_register_id' => CashRegister::factory(),
            'cash_session_id' => CashSession::factory(),
            'warehouse_id' => Warehouse::factory(),
            'customer_id' => null,
            'customer_data' => [],
            'user_id' => User::factory(),
            'status' => Sale::STATUS_DRAFT,
            'currency_code' => 'MXN',
            'subtotal_amount' => 0,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'tip_amount' => 0,
            'total_amount' => 0,
            'paid_amount' => 0,
            'change_amount' => 0,
            'metadata' => [],
        ];
    }

    public function completed(float $total = 100): self
    {
        return $this->state(fn () => [
            'status' => Sale::STATUS_COMPLETED,
            'subtotal_amount' => $total,
            'total_amount' => $total,
            'paid_amount' => $total,
            'completed_at' => now(),
        ]);
    }

    public function voided(string $reason = 'Cancelada'): self
    {
        return $this->state(fn () => [
            'status' => Sale::STATUS_VOIDED,
            'void_reason' => $reason,
            'voided_at' => now(),
        ]);
    }
}
