<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class PinVerifyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'pin' => ['required', 'string', 'regex:/^\d{4,8}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'pin.regex' => 'El PIN debe tener entre 4 y 8 dígitos numéricos.',
        ];
    }

    public function dontFlash(): array
    {
        return ['pin'];
    }
}
