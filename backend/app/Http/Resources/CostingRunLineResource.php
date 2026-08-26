<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Purchasing\Models\CostingRunLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CostingRunLine
 */
class CostingRunLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'product_uuid' => $this->product->uuid,
            'product_name' => $this->product->name,
            'pack_description' => $this->pack_description,
            'pack_price' => $this->pack_price,
            'units_per_pack' => $this->units_per_pack,
            'packs_qty' => $this->packs_qty,
            'extra_cost' => $this->extra_cost,
            'waste_pct' => $this->waste_pct,
            'margin_pct' => $this->margin_pct,
            'margin_type' => $this->margin_type,
            'computed_units' => $this->computed_units,
            'computed_unit_cost' => $this->computed_unit_cost,
            'computed_price' => $this->computed_price,
        ];
    }
}
