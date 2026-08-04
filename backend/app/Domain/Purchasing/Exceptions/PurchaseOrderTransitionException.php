<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Exceptions;

use RuntimeException;

/**
 * Transicion invalida en la maquina de estados de una OC.
 *
 * draft -> submitted -> approved -> received; cancelled desde cualquiera
 * menos received. Cubre tanto el intento de saltar un paso como el de
 * editar una OC que ya salio de draft (29.7: PATCH solo si draft).
 */
class PurchaseOrderTransitionException extends RuntimeException
{
    public function __construct(
        public readonly string $currentStatus,
        public readonly string $attempted,
    ) {
        parent::__construct(sprintf(
            'No se puede %s una orden en estado %s.',
            $attempted,
            $currentStatus
        ));
    }

    public static function forStatus(string $current, string $attempted): self
    {
        return new self($current, $attempted);
    }
}
