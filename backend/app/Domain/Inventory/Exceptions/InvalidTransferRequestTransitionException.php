<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use RuntimeException;

class InvalidTransferRequestTransitionException extends RuntimeException
{
    public static function between(string $from, string $to): self
    {
        return new self(sprintf(
            'Transicion de solicitud de transferencia no permitida: %s -> %s.',
            $from,
            $to
        ));
    }
}
