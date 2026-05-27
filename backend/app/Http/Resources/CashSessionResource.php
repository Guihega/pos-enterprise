<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Cash\Models\CashSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CashSession
 */
class CashSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'status' => $this->status,
            'opened_at' => $this->opened_at->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'opening' => [
                'amount' => (float) $this->opening_amount,
                'notes' => $this->opening_notes,
                'by' => $this->whenLoaded('openedBy', fn () => [
                    'uuid' => $this->openedBy->uuid,
                    'name' => $this->openedBy->name,
                ]),
            ],
            'closing' => $this->status !== CashSession::STATUS_OPEN ? [
                'expected_amount' => $this->expected_amount !== null ? (float) $this->expected_amount : null,
                'counted_amount' => $this->counted_amount !== null ? (float) $this->counted_amount : null,
                'difference' => $this->difference !== null ? (float) $this->difference : null,
                'notes' => $this->closing_notes,
                'by' => $this->whenLoaded('closedBy', fn () => $this->closedBy ? [
                    'uuid' => $this->closedBy->uuid,
                    'name' => $this->closedBy->name,
                ] : null),
            ] : null,
            'register' => $this->whenLoaded('register', fn () => [
                'uuid' => $this->register->uuid,
                'code' => $this->register->code,
                'name' => $this->register->name,
            ]),
            'movements_count' => $this->whenCounted('movements'),
        ];
    }
}
