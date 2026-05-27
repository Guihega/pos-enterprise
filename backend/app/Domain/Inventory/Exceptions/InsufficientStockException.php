<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use RuntimeException;

class InsufficientStockException extends RuntimeException
{
    public static function forProduct(int $productId, int $warehouseId, float $requested, float $available): self
    {
        return new self(sprintf(
            'Stock insuficiente para producto %d en almacén %d: solicitado=%g, disponible=%g',
            $productId, $warehouseId, $requested, $available
        ));
    }
}
