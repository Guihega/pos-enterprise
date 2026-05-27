<?php

declare(strict_types=1);

namespace App\Domain\Sales\Exceptions;

use RuntimeException;

class PaymentMismatchException extends RuntimeException
{
    public static function underpayment(float $total, float $paid): self
    {
        return new self(sprintf(
            'Pago insuficiente: total=%.2f, recibido=%.2f, falta=%.2f',
            $total, $paid, $total - $paid
        ));
    }

    public static function overpayWithNonCash(): self
    {
        return new self(
            'Solo se acepta sobrepago si el método de pago es efectivo (genera cambio).'
        );
    }
}
