<?php

declare(strict_types=1);

namespace App\Http\Requests\Sale;

use App\Domain\Sales\Models\SalePayment;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Valida el payload de un checkout antes de construir el CheckoutRequest DTO.
 *
 * La validación de pertenencia al tenant (productos, almacén, sesión, cliente)
 * se cablea con Rule::exists(...)->where('company_id', ...), siguiendo la
 * convención del resto de Form Requests del proyecto. La lógica de negocio
 * (suficiencia de pago, crédito disponible, stock) NO se valida aquí: vive en
 * SalesService y se traduce a HTTP vía el handler de excepciones.
 */
class StoreSaleRequest extends FormRequest
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
        $companyId = TenantContext::id();

        return [
            'cash_session_uuid' => [
                'required', 'uuid',
                Rule::exists('cash_sessions', 'uuid')->where('company_id', $companyId),
            ],
            'warehouse_uuid' => [
                'required', 'uuid',
                Rule::exists('warehouses', 'uuid')->where('company_id', $companyId),
            ],
            'customer_uuid' => [
                'nullable', 'uuid',
                Rule::exists('customers', 'uuid')->where('company_id', $companyId),
            ],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_tax_id' => ['nullable', 'string', 'max:50'],
            'tip_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'series' => ['nullable', 'string', 'max:10'],

            // ----- Items -----
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_uuid' => [
                'required', 'uuid',
                Rule::exists('products', 'uuid')->where('company_id', $companyId),
            ],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:9999999.9999'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:9999999.9999'],
            'items.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],

            // ----- Payments -----
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.method' => [
                'required', 'string',
                Rule::in([
                    SalePayment::METHOD_CASH,
                    SalePayment::METHOD_CARD_CREDIT,
                    SalePayment::METHOD_CARD_DEBIT,
                    SalePayment::METHOD_TRANSFER,
                    SalePayment::METHOD_CHECK,
                    SalePayment::METHOD_VOUCHER,
                    SalePayment::METHOD_CREDIT,
                    SalePayment::METHOD_OTHER,
                ]),
            ],
            'payments.*.amount' => ['required', 'numeric', 'gt:0', 'max:9999999.99'],
            'payments.*.tendered_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'payments.*.reference' => ['nullable', 'string', 'max:255'],
            'payments.*.authorization_code' => ['nullable', 'string', 'max:100'],
            'payments.*.card_brand' => ['nullable', 'string', 'max:50'],
            'payments.*.card_last4' => ['nullable', 'string', 'size:4'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'La venta debe tener al menos un producto.',
            'items.min' => 'La venta debe tener al menos un producto.',
            'payments.required' => 'La venta debe registrar al menos un pago.',
            'payments.min' => 'La venta debe registrar al menos un pago.',
        ];
    }
}
