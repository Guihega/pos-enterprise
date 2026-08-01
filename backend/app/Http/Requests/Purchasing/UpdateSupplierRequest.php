<?php

declare(strict_types=1);

namespace App\Http\Requests\Purchasing;

use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSupplierRequest extends FormRequest
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
        $supplier = $this->route('supplier');

        return [
            'code' => [
                'sometimes', 'required', 'string', 'max:30',
                Rule::unique('suppliers', 'code')
                    ->where('company_id', $companyId)
                    ->ignore($supplier?->id),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:200'],
            'contact_name' => ['nullable', 'string', 'max:200'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:200'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
