<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\TransferRequest;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TransferRequest>
 */
class TransferRequestFactory extends Factory
{
    protected $model = TransferRequest::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'folio' => 'TRQ-'.now()->format('Ymd').'-'.str_pad((string) $this->faker->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'from_branch_id' => Branch::factory(),
            'to_branch_id' => Branch::factory(),
            'status' => TransferRequest::STATUS_PENDING,
            'requested_by_user_id' => User::factory(),
            'notes' => null,
        ];
    }

    public function approved(): self
    {
        return $this->state(fn () => [
            'status' => TransferRequest::STATUS_APPROVED,
            'resolved_at' => now(),
        ]);
    }

    public function rejected(): self
    {
        return $this->state(fn () => [
            'status' => TransferRequest::STATUS_REJECTED,
            'resolved_at' => now(),
            'rejection_reason' => 'Sin stock disponible',
        ]);
    }

    public function cancelled(): self
    {
        return $this->state(fn () => [
            'status' => TransferRequest::STATUS_CANCELLED,
            'resolved_at' => now(),
        ]);
    }
}
