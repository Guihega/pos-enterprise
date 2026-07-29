<?php

declare(strict_types=1);

namespace App\Http\Requests\Report;

use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtros comunes de los reportes por rango.
 *
 * from y to son OBLIGATORIOS a proposito: el resumen de un dia ya lo cubre
 * /reports/sales-summary, y un default silencioso haria ambigua la semantica.
 *
 * Divergencia deliberada respecto a los reportes consolidados, que leen
 * query('from') sin validar: aqui una fecha malformada devuelve 422, no 500.
 */
class SalesRangeRequest extends FormRequest
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
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'branch_uuid' => [
                'nullable', 'uuid',
                Rule::exists('branches', 'uuid')->where('company_id', $companyId),
            ],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ];
    }

    public function from(): string
    {
        return (string) $this->validated()['from'];
    }

    public function to(): string
    {
        return (string) $this->validated()['to'];
    }

    public function branchUuid(): ?string
    {
        $uuid = $this->validated()['branch_uuid'] ?? null;

        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }

    public function limitValue(): ?int
    {
        $limit = $this->validated()['limit'] ?? null;

        return $limit !== null ? (int) $limit : null;
    }
}
