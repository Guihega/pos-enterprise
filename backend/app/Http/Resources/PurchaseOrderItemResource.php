<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Purchasing\Models\PurchaseOrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PurchaseOrderItem
 */
class PurchaseOrderItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'product_uuid' => $this->product?->uuid,
            'quantity' => $this->quantity,
            'quantity_received' => $this->quantity_received,
            'unit_cost' => $this->unit_cost,
            'tax_rate' => $this->tax_rate,
            'tax_amount' => $this->tax_amount,
            'subtotal' => $this->subtotal,
            'total' => $this->total,
        ];
    }
}
