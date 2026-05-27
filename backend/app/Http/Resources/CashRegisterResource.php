<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Cash\Models\CashRegister;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CashRegister
 */
class CashRegisterResource extends JsonResource
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
            'is_active' => $this->is_active,
            'has_open_session' => $this->whenAppended('has_open_session', fn () => (bool) $this->has_open_session),
            'branch' => $this->whenLoaded('branch', fn () => [
                'uuid' => $this->branch->uuid,
                'code' => $this->branch->code,
                'name' => $this->branch->name,
            ]),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
