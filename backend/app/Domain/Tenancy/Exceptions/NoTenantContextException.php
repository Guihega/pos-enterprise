<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Exceptions;

use RuntimeException;

/**
 * Se lanza cuando un componente intenta operar sin tenant en contexto.
 * Es un error de programación, no de usuario; debe explotar fuerte y
 * temprano.
 */
final class NoTenantContextException extends RuntimeException {}
