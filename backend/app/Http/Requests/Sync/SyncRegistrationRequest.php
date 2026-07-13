<?php

declare(strict_types=1);

namespace App\Http\Requests\Sync;

use App\Domain\Sync\Models\SyncDevice;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncRegistrationRequest extends FormRequest
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
            'device_id' => ['required', 'string', 'max:100'],
            'branch_uuid' => [
                'required', 'uuid',
                Rule::exists('branches', 'uuid')->where('company_id', $companyId),
            ],
            'type' => ['required', 'string', Rule::in(SyncDevice::TYPES)],
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'fingerprint' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settings' => ['sometimes', 'array'],
        ];
    }
}
