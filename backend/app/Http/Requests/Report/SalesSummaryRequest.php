<?php

declare(strict_types=1);

namespace App\Http\Requests\Report;

use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SalesSummaryRequest extends FormRequest
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
            'date' => ['nullable', 'date_format:Y-m-d'],
            'branch_uuid' => [
                'nullable', 'uuid',
                Rule::exists('branches', 'uuid')->where('company_id', $companyId),
            ],
        ];
    }

    public function resolvedDate(): string
    {
        $date = $this->validated()['date'] ?? null;

        return is_string($date) && $date !== ''
            ? $date
            : now()->toDateString();
    }

    public function branchUuid(): ?string
    {
        $uuid = $this->validated()['branch_uuid'] ?? null;

        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }
}
