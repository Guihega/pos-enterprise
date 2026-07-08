<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use RuntimeException;

/**
 * EX-041: lote vencido no puede venderse (bloquear venta de ese lote).
 */
class ExpiredBatchException extends RuntimeException
{
    public static function forProduct(int $productId, string $expirationDate): self
    {
        return new self(sprintf(
            'El lote disponible del producto %d esta vencido (caducidad %s); no puede venderse.',
            $productId,
            $expirationDate
        ));
    }
}
