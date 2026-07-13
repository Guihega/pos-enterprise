<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Sync\Models\SyncDevice;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SyncDevice>
 */
class SyncDeviceFactory extends Factory
{
    protected $model = SyncDevice::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'device_id' => 'dev-'.Str::random(12),
            'name' => $this->faker->word().' POS',
            'type' => SyncDevice::TYPE_POS,
            'is_active' => true,
            'settings' => [],
        ];
    }

    public function ofBranch($branch): static
    {
        return $this->state(fn () => [
            'company_id' => $branch->company_id,
            'branch_id' => $branch->id,
        ]);
    }

    public function stale(int $hours = 3): static
    {
        return $this->state(fn () => ['last_seen_at' => now()->subHours($hours)]);
    }
}
