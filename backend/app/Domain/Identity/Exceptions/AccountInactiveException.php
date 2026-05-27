<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

use Exception;

final class AccountInactiveException extends Exception
{
    public function __construct(string $message = 'La cuenta está desactivada.')
    {
        parent::__construct($message);
    }
}
