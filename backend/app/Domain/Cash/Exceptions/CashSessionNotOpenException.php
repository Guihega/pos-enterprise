<?php

declare(strict_types=1);

namespace App\Domain\Cash\Exceptions;

use RuntimeException;

class CashSessionNotOpenException extends RuntimeException
{
    public static function forSession(int $sessionId, string $status): self
    {
        return new self("La sesión {$sessionId} no está abierta (status: {$status})");
    }
}
