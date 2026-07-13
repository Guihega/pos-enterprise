<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Inventory\Models\Batch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Batch
 */
class BatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'lot_number' => $this->lot_number,
            'status' => $this->status,
            'product' => $this->whenLoaded('product', fn () => [
                'uuid' => $this->product->uuid,
                'sku' => $this->product->sku,
                'name' => $this->product->name,
            ]),
            'branch' => $this->whenLoaded('branch', fn () => [
                'uuid' => $this->branch->uuid,
                'code' => $this->branch->code,
                'name' => $this->branch->name,
            ]),
            'warehouse' => $this->whenLoaded('warehouse', fn () => $this->warehouse ? [
                'uuid' => $this->warehouse->uuid,
                'code' => $this->warehouse->code,
                'name' => $this->warehouse->name,
            ] : null),
            'expiration_date' => $this->expiration_date?->toDateString(),
            'received_date' => $this->received_date->toDateString(),
            'quantities' => [
                'received' => (float) $this->received_quantity,
                'remaining' => (float) $this->quantity,
            ],
            'cost' => (float) $this->cost,
            'is_expired' => $this->isExpired(),
            'is_quarantined' => $this->isQuarantined(),
            'notes' => $this->notes,
        ];
    }
}
