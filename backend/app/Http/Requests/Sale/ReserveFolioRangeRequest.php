<?php

declare(strict_types=1);

namespace App\Http\Requests\Sale;

use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReserveFolioRangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = TenantContext::id();

        return [
            'cash_register_uuid' => [
                'required', 'uuid',
                Rule::exists('cash_registers', 'uuid')->where('company_id', $companyId),
            ],
            'series' => ['sometimes', 'string', 'max:10'],
            'device_id' => ['required', 'string', 'max:36'],
            'size' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ];
    }
}
