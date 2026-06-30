<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Inventory\Models\Transfer;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Transfer>
 */
class TransferFactory extends Factory
{
    protected $model = Transfer::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'folio' => 'TR-'.now()->format('Ymd').'-'.str_pad((string) $this->faker->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'from_branch_id' => Branch::factory(),
            'to_branch_id' => Branch::factory(),
            'from_warehouse_id' => null,
            'to_warehouse_id' => null,
            'status' => Transfer::STATUS_DRAFT,
            'transport_method' => null,
            'transport_reference' => null,
            'notes' => null,
            'total_cost' => 0,
        ];
    }

    public function sent(): self
    {
        return $this->state(fn () => [
            'status' => Transfer::STATUS_SENT,
            'sent_at' => now(),
        ]);
    }

    public function received(): self
    {
        return $this->state(fn () => [
            'status' => Transfer::STATUS_RECEIVED,
            'sent_at' => now()->subDay(),
            'received_at' => now(),
        ]);
    }

    public function cancelled(): self
    {
        return $this->state(fn () => [
            'status' => Transfer::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);
    }
}
