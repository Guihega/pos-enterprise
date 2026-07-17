<?php

declare(strict_types=1);

namespace App\Http\Requests\Sync;

use App\Domain\Sync\Models\SyncConflict;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveSyncConflictRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'resolution' => ['required', 'string', Rule::in(SyncConflict::RESOLUTIONS)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
