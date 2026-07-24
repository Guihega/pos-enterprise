<?php

declare(strict_types=1);

namespace App\Domain\Sales\Exceptions;

use RuntimeException;

/**
 * 39.1 "producto eliminado": el checkout no encontro el producto en el
 * tenant (inexistente o soft-borrado, excluido por el scope global).
 * Excepcion dedicada para que SyncBatchService la distinga de los otros
 * usos de InvalidArgumentException alcanzables desde checkout (pago
 * credit sin cliente) y la persista como conflicto en la cola 39.3.
 */
class SaleProductNotFoundException extends RuntimeException
{
    public function __construct(public readonly string $productUuid)
    {
        parent::__construct(sprintf('Producto %s no encontrado en este tenant', $productUuid));
    }

    public static function forUuid(string $uuid): self
    {
        return new self($uuid);
    }
}
