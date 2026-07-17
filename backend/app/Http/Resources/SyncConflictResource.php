<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Sync\Models\SyncConflict;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SyncConflict
 */
class SyncConflictResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'entity_type' => $this->entity_type,
            'entity_uuid' => $this->entity_uuid,
            'conflict_type' => $this->conflict_type,
            'device_id' => $this->device_id,
            'client_data' => $this->client_data,
            'server_data' => $this->server_data,
            'resolution' => $this->resolution,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
