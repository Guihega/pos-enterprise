<?php

declare(strict_types=1);

namespace App\Http\Requests\Sale;

use Illuminate\Foundation\Http\FormRequest;

class CancelSaleRequest extends FormRequest
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
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Debe indicar el motivo de la cancelación.',
            'reason.min' => 'El motivo de la cancelación es demasiado corto.',
        ];
    }
}
