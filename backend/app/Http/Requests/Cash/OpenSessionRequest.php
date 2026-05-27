<?php

declare(strict_types=1);

namespace App\Http\Requests\Cash;

use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OpenSessionRequest extends FormRequest
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
            'cash_register_uuid' => [
                'required', 'uuid',
                Rule::exists('cash_registers', 'uuid')->where('company_id', $companyId),
            ],
            'opening_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'opening_notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
