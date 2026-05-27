<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Exceptions;

use RuntimeException;

/**
 * Se lanza cuando un modelo se intenta crear/asignar con un company_id
 * distinto del tenant en contexto. Es un intento de cross-tenant access:
 * podría ser un ataque o un bug. En cualquier caso debe explotar.
 */
final class CrossTenantAccessException extends RuntimeException
{
    public static function forModel(string $modelClass, int $expectedCompanyId, int $attemptedCompanyId): self
    {
        return new self(sprintf(
            'Intento de acceso cross-tenant detectado en %s: tenant en contexto = %d, '.
            'company_id intentado = %d.',
            $modelClass,
            $expectedCompanyId,
            $attemptedCompanyId
        ));
    }
}
