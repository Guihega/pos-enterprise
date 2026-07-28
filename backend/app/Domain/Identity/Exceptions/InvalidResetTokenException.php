<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

use RuntimeException;

/**
 * Token de reset invalido: inexistente, ya consumido o vencido.
 *
 * Mensaje deliberadamente generico para no revelar cual de las tres
 * condiciones fallo (mismo criterio anti-enumeracion que login).
 */
final class InvalidResetTokenException extends RuntimeException
{
    public static function make(): self
    {
        return new self('El token de recuperacion es invalido o expiro');
    }
}
