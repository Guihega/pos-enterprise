<?php

declare(strict_types=1);

namespace App\Domain\Cash\Exceptions;

use RuntimeException;

class CashSessionAlreadyOpenException extends RuntimeException
{
    public static function forRegister(int $registerId): self
    {
        return new self("La caja {$registerId} ya tiene una sesión abierta");
    }
}
