<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Exceptions;

use RuntimeException;

/**
 * Conflicto de estado en una factura de proveedor.
 *
 * A diferencia de la OC, la factura NO tiene maquina de estados: su status se
 * deriva de paid_amount (pending -> partial -> paid). Esta excepcion cubre los
 * conflictos que si existen: conciliar una factura ya conciliada, o pagar una
 * que ya esta saldada.
 *
 * No se reutiliza PurchaseOrderTransitionException porque el code de la
 * respuesta es contrato de API: un error de factura no puede devolver
 * PURCHASE_ORDER_TRANSITION.
 */
class SupplierInvoiceTransitionException extends RuntimeException
{
    public function __construct(
        public readonly string $currentStatus,
        public readonly string $attempted,
    ) {
        parent::__construct(sprintf(
            'No se puede %s una factura en estado %s.',
            $attempted,
            $currentStatus
        ));
    }

    public static function forStatus(string $current, string $attempted): self
    {
        return new self($current, $attempted);
    }
}
