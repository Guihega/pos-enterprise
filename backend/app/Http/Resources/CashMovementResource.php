<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Cash\Models\CashMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CashMovement
 */
class CashMovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'type' => $this->type,
            'amount' => (float) $this->amount,
            'delta_signed' => (float) $this->delta_signed,
            'reason' => $this->reason,
            'reference' => $this->reference,
            'movement_at' => $this->movement_at->toIso8601String(),
            'user' => $this->whenLoaded('user', fn () => [
                'uuid' => $this->user->uuid,
                'name' => $this->user->name,
            ]),
        ];
    }
}
