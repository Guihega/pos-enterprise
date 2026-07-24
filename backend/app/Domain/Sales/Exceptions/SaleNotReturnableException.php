<?php

declare(strict_types=1);

namespace App\Domain\Sales\Exceptions;

use RuntimeException;

/**
 * Devolucion rechazada por regla de negocio (CU-CAJ-010): estado de
 * venta invalido, ventana RN-085 vencida, cantidades que exceden lo
 * vendido menos lo ya devuelto, o sesion de caja cerrada.
 */
class SaleNotReturnableException extends RuntimeException
{
    public static function forStatus(string $status): self
    {
        return new self(sprintf('La venta no admite devolucion en estado %s', $status));
    }

    public static function windowExpired(int $days): self
    {
        return new self(sprintf('Ventana de devolucion vencida (RN-085: %d dias)', $days));
    }

    public static function quantityExceeded(string $productUuid): self
    {
        return new self(sprintf('Cantidad a devolver excede lo disponible del producto %s', $productUuid));
    }

    public static function sessionClosed(): self
    {
        return new self('Se requiere una sesion de caja abierta para reembolsar');
    }
}
