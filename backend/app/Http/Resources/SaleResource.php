<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Sales\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Sale
 */
class SaleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'number' => $this->number,
            'series' => $this->series,
            'status' => $this->status,
            'currency_code' => $this->currency_code,

            'totals' => [
                'subtotal' => (float) $this->subtotal_amount,
                'discount' => (float) $this->discount_amount,
                'tax' => (float) $this->tax_amount,
                'tip' => (float) $this->tip_amount,
                'total' => (float) $this->total_amount,
                'paid' => (float) $this->paid_amount,
                'change' => (float) $this->change_amount,
            ],

            'customer' => [
                'uuid' => $this->whenLoaded('customer', fn () => $this->customer?->uuid),
                'name' => $this->customer_name,
                'tax_id' => $this->customer_tax_id,
            ],

            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'uuid' => $item->uuid,
                'product_uuid' => $item->relationLoaded('product') && $item->product ? $item->product->uuid : null,
                'product_sku' => $item->product_sku,
                'product_name' => $item->product_name,
                'unit_name' => $item->unit_name,
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'unit_cost' => (float) $item->unit_cost,
                'line_subtotal' => (float) $item->line_subtotal,
                'discount_percent' => (float) $item->discount_percent,
                'discount_amount' => (float) $item->discount_amount,
                'is_taxable' => (bool) $item->is_taxable,
                'tax_inclusive' => (bool) $item->tax_inclusive,
                'tax_rate' => (float) $item->tax_rate,
                'tax_amount' => (float) $item->tax_amount,
                'tax_code' => $item->tax_code,
                'line_total' => (float) $item->line_total,
            ])),

            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($payment) => [
                'uuid' => $payment->uuid,
                'method' => $payment->method,
                'amount' => (float) $payment->amount,
                'tendered_amount' => $payment->tendered_amount !== null ? (float) $payment->tendered_amount : null,
                'reference' => $payment->reference,
                'card_brand' => $payment->card_brand,
                'card_last4' => $payment->card_last4,
                'captured_at' => $payment->captured_at->toIso8601String(),
            ])),

            'taxes' => $this->whenLoaded('taxes', fn () => $this->taxes->map(fn ($tax) => [
                'code' => $tax->code,
                'name' => $tax->name,
                'rate' => (float) $tax->rate,
                'taxable_base' => (float) $tax->taxable_base,
                'amount' => (float) $tax->amount,
            ])),

            'notes' => $this->notes,

            'void' => $this->status === Sale::STATUS_VOIDED ? [
                'reason' => $this->void_reason,
                'voided_at' => $this->voided_at?->toIso8601String(),
                'by' => $this->whenLoaded('voider', fn () => $this->voider ? [
                    'uuid' => $this->voider->uuid,
                    'name' => $this->voider->name,
                ] : null),
            ] : null,

            'cashier' => $this->whenLoaded('user', fn () => $this->user ? [
                'uuid' => $this->user->uuid,
                'name' => $this->user->name,
            ] : null),

            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
