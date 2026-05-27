<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Cash\Models\CashMovement;
use App\Domain\Cash\Models\CashSession;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CashMovement>
 */
class CashMovementFactory extends Factory
{
    protected $model = CashMovement::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'cash_session_id' => CashSession::factory(),
            'user_id' => User::factory(),
            'type' => CashMovement::TYPE_CASH_IN,
            'amount' => 100,
            'delta_signed' => 100,
            'metadata' => [],
            'movement_at' => now(),
        ];
    }
}
