<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Inventory\Models\TransferRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TransferRequest
 */
class TransferRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'folio' => $this->folio,
            'status' => $this->status,
            'from_branch' => $this->whenLoaded('fromBranch', fn () => [
                'uuid' => $this->fromBranch->uuid,
                'code' => $this->fromBranch->code,
                'name' => $this->fromBranch->name,
            ]),
            'to_branch' => $this->whenLoaded('toBranch', fn () => [
                'uuid' => $this->toBranch->uuid,
                'code' => $this->toBranch->code,
                'name' => $this->toBranch->name,
            ]),
            'requester' => $this->whenLoaded('requester', fn () => [
                'uuid' => $this->requester->uuid,
                'name' => $this->requester->name,
            ]),
            'resolver' => $this->whenLoaded('resolver', fn () => $this->resolver === null ? null : [
                'uuid' => $this->resolver->uuid,
                'name' => $this->resolver->name,
            ]),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'transfer' => $this->whenLoaded('transfer', fn () => $this->transfer === null ? null : [
                'uuid' => $this->transfer->uuid,
                'folio' => $this->transfer->folio,
                'status' => $this->transfer->status,
            ]),
            'notes' => $this->notes,
            'items' => TransferRequestItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
