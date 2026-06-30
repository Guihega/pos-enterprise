<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Inventory\Models\Transfer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Transfer
 */
class TransferResource extends JsonResource
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
            'transport' => [
                'method' => $this->transport_method,
                'reference' => $this->transport_reference,
            ],
            'total_cost' => (float) $this->total_cost,
            'notes' => $this->notes,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'received_at' => $this->received_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'items' => TransferItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
