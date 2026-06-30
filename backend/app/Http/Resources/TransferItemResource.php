<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Inventory\Models\TransferItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TransferItem
 */
class TransferItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $sent = (float) $this->quantity_sent;
        $received = $this->quantity_received !== null ? (float) $this->quantity_received : null;

        return [
            'uuid' => $this->whenLoaded('product', fn () => $this->product->uuid),
            'product' => $this->whenLoaded('product', fn () => [
                'uuid' => $this->product->uuid,
                'sku' => $this->product->sku,
                'name' => $this->product->name,
            ]),
            'quantity' => [
                'sent' => $sent,
                'received' => $received,
                'loss' => $received !== null ? round($sent - $received, 4) : null,
            ],
            'unit_cost' => (float) $this->unit_cost,
            'notes' => $this->notes,
        ];
    }
}
