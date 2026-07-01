<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenancy;

use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBranchRequest extends FormRequest
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
        $branch = $this->route('branch');

        return [
            'code' => [
                'sometimes', 'required', 'string', 'max:20',
                Rule::unique('branches', 'code')
                    ->where('company_id', $companyId)
                    ->ignore($branch?->id),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:200'],
            'tax_id' => ['nullable', 'string', 'max:30'],
            'series' => ['nullable', 'string', 'max:10'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'state' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:200'],
            'timezone' => ['nullable', 'string', 'max:50'],
        ];
    }
}
