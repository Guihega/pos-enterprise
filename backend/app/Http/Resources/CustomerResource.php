<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Customer\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Customer
 */
class CustomerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'code' => $this->code,
            'type' => $this->type,
            'name' => $this->name,
            'legal_name' => $this->legal_name,
            'tax' => [
                'tax_id' => $this->tax_id,
                'data' => $this->tax_data,
            ],
            'contact' => [
                'email' => $this->email,
                'phone' => $this->phone,
                'mobile' => $this->mobile,
            ],
            'address' => [
                'line' => $this->address_line,
                'city' => $this->city,
                'state' => $this->state,
                'postal_code' => $this->postal_code,
                'country_code' => $this->country_code,
            ],
            'credit' => [
                'limit' => (float) $this->credit_limit,
                'balance' => (float) $this->credit_balance,
                'available' => $this->availableCredit(),
            ],
            'flags' => [
                'is_active' => $this->is_active,
                'is_blocked' => $this->is_blocked,
                'blocked_reason' => $this->blocked_reason,
            ],
            'notes' => $this->notes,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
