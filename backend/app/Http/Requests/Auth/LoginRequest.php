<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
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
            'email' => ['required', 'string', 'email:strict', 'max:200'],
            'password' => ['required', 'string', 'min:1', 'max:255'],
            'device_id' => ['nullable', 'string', 'max:100'],
            'token_name' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.email' => 'El correo no tiene un formato válido.',
            'email.required' => 'El correo es obligatorio.',
            'password.required' => 'La contraseña es obligatoria.',
        ];
    }

    /**
     * NO mostrar el password en respuestas de validación o logs.
     */
    public function dontFlash(): array
    {
        return ['password', 'password_confirmation'];
    }

    public function loginEmail(): string
    {
        return strtolower(trim((string) $this->validated('email')));
    }

    public function loginPassword(): string
    {
        return (string) $this->validated('password');
    }

    /**
     * Contexto para auditoría (IP real respetando proxies, UA, device).
     *
     * @return array{ip: string, user_agent: string|null, device_id: string|null, token_name: string|null}
     */
    public function loginContext(): array
    {
        return [
            'ip' => $this->ip(),
            'user_agent' => $this->userAgent(),
            'device_id' => $this->validated('device_id') ?? $this->header('X-Device-Id'),
            'token_name' => $this->validated('token_name') ?? 'pos-session',
        ];
    }
}
