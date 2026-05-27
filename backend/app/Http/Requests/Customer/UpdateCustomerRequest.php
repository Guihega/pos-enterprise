<?php

declare(strict_types=1);

namespace App\Http\Requests\Customer;

use App\Domain\Customer\Models\Customer;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerRequest extends FormRequest
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

        /** @var Customer|null $customer */
        $customer = $this->route('customer');
        $customerId = $customer?->id;

        return [
            'code' => [
                'sometimes', 'nullable', 'string', 'max:50',
                Rule::unique('customers', 'code')
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at')
                    ->ignore($customerId),
            ],
            'type' => ['sometimes', 'required', Rule::in([Customer::TYPE_INDIVIDUAL, Customer::TYPE_BUSINESS])],
            'name' => ['sometimes', 'required', 'string', 'max:200'],
            'legal_name' => ['nullable', 'string', 'max:200'],

            'tax_id' => [
                'sometimes', 'nullable', 'string', 'max:50',
                Rule::unique('customers', 'tax_id')
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at')
                    ->ignore($customerId),
            ],
            'tax_data' => ['nullable', 'array'],

            'email' => [
                'sometimes', 'nullable', 'email', 'max:200',
                Rule::unique('customers', 'email')
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at')
                    ->ignore($customerId),
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
