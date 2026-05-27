<?php

declare(strict_types=1);

namespace App\Http\Requests\Cash;

use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCashRegisterRequest extends FormRequest
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
            'branch_uuid' => [
                'required', 'uuid',
                Rule::exists('branches', 'uuid')->where('company_id', $companyId),
            ],
            'code' => [
                'required', 'string', 'max:30',
                Rule::unique('cash_registers', 'code')->where('company_id', $companyId),
            ],
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
