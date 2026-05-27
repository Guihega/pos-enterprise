<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Cash\Models\CashRegister;
use App\Domain\Cash\Models\CashSession;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CashSession>
 */
class CashSessionFactory extends Factory
{
    protected $model = CashSession::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'cash_register_id' => CashRegister::factory(),
            'opened_by' => User::factory(),
            'status' => CashSession::STATUS_OPEN,
            'opening_amount' => 1000,
            'opened_at' => now(),
        ];
    }

    public function open(): self
    {
        return $this->state(fn () => ['status' => CashSession::STATUS_OPEN]);
    }

    public function closed(float $expected = 1500, float $counted = 1500): self
    {
        return $this->state(fn () => [
            'status' => CashSession::STATUS_CLOSED,
            'expected_amount' => $expected,
            'counted_amount' => $counted,
            'difference' => round($counted - $expected, 2),
            'closed_at' => now(),
        ]);
    }
}
