<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Inventory\Models\Stock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Stock
 */
class StockResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
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
                'on_hand' => (float) $this->quantity_on_hand,
                'reserved' => (float) $this->quantity_reserved,
                'available' => $this->quantity_available,
            ],
            'thresholds' => [
                'min' => $this->stock_min !== null ? (float) $this->stock_min : null,
                'max' => $this->stock_max !== null ? (float) $this->stock_max : null,
                'is_low' => $this->isLowStock(),
                'is_overstock' => $this->isOverstock(),
            ],
            'average_cost' => (float) $this->average_cost,
            'last_movement_at' => $this->last_movement_at?->toIso8601String(),
        ];
    }
}
