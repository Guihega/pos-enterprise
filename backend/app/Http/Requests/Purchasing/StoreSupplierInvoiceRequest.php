<?php

declare(strict_types=1);

namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Alta de factura de proveedor.
 *
 * subtotal y tax_total se capturan; total lo calcula el servicio como su
 * suma, para que no pueda llegar un total incoherente desde el cliente.
 *
 * purchase_order_uuid NO se acepta: vincular es responsabilidad de match(),
 * que ademas valida importes contra lo recibido.
 *
 * Valida FORMA, no existencia (sin Rule::exists): el servicio resuelve los
 * uuid y devuelve 404 o 422 segun corresponda.
 */
class StoreSupplierInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'supplier_uuid' => ['required', 'uuid'],
            'folio' => ['required', 'string', 'max:80'],
            'issue_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:issue_date'],
            'subtotal' => ['required', 'numeric', 'min:0'],
            'tax_total' => ['required', 'numeric', 'min:0'],
            'cfdi_uuid' => ['nullable', 'uuid'],
            'cfdi_xml_url' => ['nullable', 'string', 'max:500'],
            'payment_method' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
