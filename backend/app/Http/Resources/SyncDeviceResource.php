<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Sync\Models\SyncDevice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SyncDevice
 */
class SyncDeviceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'device_id' => $this->device_id,
            'name' => $this->name,
            'type' => $this->type,
            'is_active' => $this->is_active,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'last_sync_at' => $this->last_sync_at?->toIso8601String(),
        ];
    }
}
