<?php

declare(strict_types=1);

namespace App\Http\Requests\Customer;

use App\Domain\Customer\Models\Customer;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
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
            'code' => [
                'nullable', 'string', 'max:50',
                Rule::unique('customers', 'code')
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at'),
            ],
            'type' => ['required', Rule::in([Customer::TYPE_INDIVIDUAL, Customer::TYPE_BUSINESS])],
            'name' => ['required', 'string', 'max:200'],
            'legal_name' => ['nullable', 'string', 'max:200'],

            'tax_id' => [
                'nullable', 'string', 'max:50',
                Rule::unique('customers', 'tax_id')
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at'),
            ],
            'tax_data' => ['nullable', 'array'],

            'email' => [
                'nullable', 'email', 'max:200',
                Rule::unique('customers', 'email')
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at'),
            ],
            'phone' => ['nullable', 'string', 'max:30'],
            'mobile' => ['nullable', 'string', 'max:30'],

            'address_line' => ['nullable', 'string', 'max:300'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country_code' => ['nullable', 'string', 'size:2'],

            'credit_limit' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],

            'is_active' => ['nullable', 'boolean'],
            'is_blocked' => ['nullable', 'boolean'],
            'blocked_reason' => ['nullable', 'string', 'max:500'],

            'notes' => ['nullable', 'string'],
        ];
    }
}
