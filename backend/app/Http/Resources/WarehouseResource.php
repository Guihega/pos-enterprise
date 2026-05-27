<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Inventory\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Warehouse
 */
class WarehouseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'type' => $this->type,
            'is_sellable' => $this->is_sellable,
            'is_default' => $this->is_default,
            'is_active' => $this->is_active,
            'branch' => $this->whenLoaded('branch', fn () => [
                'uuid' => $this->branch->uuid,
                'code' => $this->branch->code,
                'name' => $this->branch->name,
            ]),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
