<?php

declare(strict_types=1);

namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Registro de un pago a proveedor.
 *
 * amount usa gt:0 (un pago de cero no es un pago), a diferencia de los
 * importes de la factura que admiten min:0. Es la misma distincion que el
 * repo ya hace entre quantity (gt:0) y unit_cost (min:0).
 */
class PaySupplierInvoiceRequest extends FormRequest
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
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_date' => ['required', 'date'],
            'method' => ['required', 'string', 'max:40'],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
