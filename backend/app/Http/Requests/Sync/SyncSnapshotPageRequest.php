<?php

declare(strict_types=1);

namespace App\Http\Requests\Sync;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pagina de snapshot (sec. 38.6): cursor keyset opcional.
 */
final class SyncSnapshotPageRequest extends FormRequest
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
        return [
            'cursor' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function cursor(): ?string
    {
        $value = $this->validated('cursor');

        return $value !== null ? (string) $value : null;
    }
}
