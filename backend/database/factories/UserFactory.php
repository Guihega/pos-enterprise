<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /** @var string Password compartido para todos los tests */
    public const TEST_PASSWORD = 'password123';

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'branch_id' => null,
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'username' => null,
            'email_verified_at' => now(),
            'password' => Hash::make(self::TEST_PASSWORD),
            'pin_hash' => null,
            'is_active' => true,
            'must_change_password' => false,
            'failed_login_attempts' => 0,
            'preferences' => [],
        ];
    }

    public function withPin(string $pin = '5872'): self
    {
        return $this->state(fn () => [
            'pin_hash' => Hash::make($pin),
            'pin_set_at' => now(),
        ]);
    }

    public function locked(): self
    {
        return $this->state(fn () => [
            'failed_login_attempts' => 5,
            'locked_until' => now()->addMinutes(15),
        ]);
    }

    public function inactive(): self
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function unverified(): self
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }
}
