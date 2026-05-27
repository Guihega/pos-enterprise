<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

use Exception;

final class InvalidCredentialsException extends Exception
{
    public function __construct(string $message = 'Credenciales inválidas.')
    {
        parent::__construct($message);
    }
}
