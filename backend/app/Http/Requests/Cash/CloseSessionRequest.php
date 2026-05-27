<?php

declare(strict_types=1);

namespace App\Http\Requests\Cash;

use Illuminate\Foundation\Http\FormRequest;

class CloseSessionRequest extends FormRequest
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
            'counted_amount' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
            'closing_notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
