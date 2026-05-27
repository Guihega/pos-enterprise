<?php

declare(strict_types=1);

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Models\User;
use Laravel\Sanctum\NewAccessToken;

/**
 * Resultado del login. DTO inmutable.
 */
final readonly class LoginResult
{
    public function __construct(
        public User $user,
        public NewAccessToken $token,
    ) {}

    /**
     * El plain-text token para enviar al cliente. Sólo accesible una vez.
     */
    public function plainTextToken(): string
    {
        return $this->token->plainTextToken;
    }
}
