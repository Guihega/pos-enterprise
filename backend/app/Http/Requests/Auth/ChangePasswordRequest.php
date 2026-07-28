<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class ChangePasswordRequest extends FormRequest
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
            'current_password' => ['required', 'string', 'max:255'],
            'new_password' => ['required', 'string', 'min:8', 'max:255', 'different:current_password'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.required' => 'La contrasena actual es obligatoria.',
            'new_password.required' => 'La nueva contrasena es obligatoria.',
            'new_password.min' => 'La nueva contrasena debe tener al menos 8 caracteres.',
            'new_password.different' => 'La nueva contrasena debe ser distinta de la actual.',
        ];
    }
}
