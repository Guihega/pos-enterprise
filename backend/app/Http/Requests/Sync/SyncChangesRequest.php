<?php

declare(strict_types=1);

namespace App\Http\Requests\Sync;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida los query params de GET /api/v1/sync/changes (sec. 38.5).
 *
 * since:    ISO timestamp opcional. Si falta, el servidor devuelve
 *           snapshot completo (todo created).
 * entities: lista separada por comas. Requerida. Solo se procesan las
 *           entidades con modelo (products, taxes, customers).
 */
class SyncChangesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'since'    => ['nullable', 'date'],
            'entities' => ['required', 'string'],
        ];
    }

    /**
     * Devuelve las entidades solicitadas como array limpio.
     * @return string[]
     */
    public function entitiesList(): array
    {
        $raw = (string) $this->query('entities', '');

        return collect(explode(',', $raw))
            ->map(fn (string $e) => trim($e))
            ->filter(fn (string $e) => $e !== '')
            ->unique()
            ->values()
            ->all();
    }

    public function since(): ?string
    {
        $since = $this->query('since');

        return is_string($since) && $since !== '' ? $since : null;
    }
}
