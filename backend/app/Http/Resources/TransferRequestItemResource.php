<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Inventory\Models\TransferRequestItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TransferRequestItem
 */
class TransferRequestItemResource extends JsonResource
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
            'quantity' => (float) $this->quantity,
            'notes' => $this->notes,
        ];
    }
}
