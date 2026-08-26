<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Exceptions;

use RuntimeException;

/**
 * Transicion invalida de una corrida de costeo. El handler la convierte
 * en 409, patron de PurchaseOrderTransitionException (decision 14).
 */
class CostingRunTransitionException extends RuntimeException {}
