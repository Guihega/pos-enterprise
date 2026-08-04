<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Purchasing\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PurchaseOrder>
 */
class PurchaseOrderFactory extends Factory
{
    protected $model = PurchaseOrder::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $n = $this->faker->unique()->numberBetween(1, 999999);

        return [
            'uuid' => (string) Str::uuid(),
            'folio' => 'OC-'.str_pad((string) $n, 6, '0', STR_PAD_LEFT),
            'status' => PurchaseOrder::STATUS_DRAFT,
            'subtotal' => 0,
            'tax_total' => 0,
            'total' => 0,
        ];
    }

    public function submitted(): static
    {
        return $this->state(fn (array $a): array => [
            'status' => PurchaseOrder::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $a): array => [
            'status' => PurchaseOrder::STATUS_APPROVED,
            'submitted_at' => now(),
            'approved_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $a): array => [
            'status' => PurchaseOrder::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);
    }
}
