<?php

declare(strict_types=1);

namespace App\Domain\Sales\Exceptions;

use RuntimeException;

class InsufficientCreditException extends RuntimeException
{
    public static function forCustomer(int $customerId, float $requested, float $available): self
    {
        return new self(sprintf(
            'Crédito insuficiente para cliente %d: solicitado=%g, disponible=%g',
            $customerId, $requested, $available
        ));
    }
}
