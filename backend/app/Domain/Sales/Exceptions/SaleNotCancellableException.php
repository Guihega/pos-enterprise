<?php

declare(strict_types=1);

namespace App\Domain\Sales\Exceptions;

use RuntimeException;

class SaleNotCancellableException extends RuntimeException
{
    public static function forStatus(string $currentStatus): self
    {
        return new self(
            "No se puede cancelar una venta en estado '{$currentStatus}'. Solo se cancelan ventas completadas."
        );
    }
}
