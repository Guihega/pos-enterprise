<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Inventory\Models\InventoryMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin InventoryMovement
 */
class InventoryMovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'type' => $this->type,
            'movement_at' => $this->movement_at->toIso8601String(),
            'product' => $this->whenLoaded('product', fn () => [
                'uuid' => $this->product->uuid,
                'sku' => $this->product->sku,
                'name' => $this->product->name,
            ]),
            'warehouse' => $this->whenLoaded('warehouse', fn () => [
                'uuid' => $this->warehouse->uuid,
                'code' => $this->warehouse->code,
                'name' => $this->warehouse->name,
            ]),
            'quantity' => [
                'delta' => (float) $this->quantity_delta,
                'after' => (float) $this->quantity_after,
            ],
            'cost' => [
                'unit' => (float) $this->unit_cost,
                'total' => (float) $this->total_cost,
                'average_after' => (float) $this->average_cost_after,
            ],
            'transfer_id' => $this->transfer_id,
            'reason' => $this->reason,
            'reference' => $this->reference,
            'user' => $this->whenLoaded('user', fn () => $this->user ? [
                'uuid' => $this->user->uuid,
                'name' => $this->user->name,
            ] : null),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
