<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Purchasing\Models\SupplierInvoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SupplierInvoice
 */
class SupplierInvoiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'folio' => $this->folio,
            'status' => $this->status,
            'supplier_uuid' => $this->supplier?->uuid,
            'purchase_order_uuid' => $this->purchaseOrder?->uuid,
            'cfdi_uuid' => $this->cfdi_uuid,
            'cfdi_xml_url' => $this->cfdi_xml_url,
            'issue_date' => $this->issue_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'subtotal' => $this->subtotal,
            'tax_total' => $this->tax_total,
            'total' => $this->total,
            'paid_amount' => $this->paid_amount,
            // balance NO es columna (ver migracion 000050): se calcula al vuelo
            // como total - paid_amount. paid_amount es la unica fuente de verdad.
            'balance' => $this->balance(),
            'payment_method' => $this->payment_method,
            'notes' => $this->notes,
            'payments' => SupplierPaymentResource::collection($this->whenLoaded('payments')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
