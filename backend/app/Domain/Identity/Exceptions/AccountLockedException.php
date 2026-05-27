<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

use Exception;
use Illuminate\Support\Carbon;

final class AccountLockedException extends Exception
{
    public function __construct(
        public readonly Carbon $lockedUntil,
        string $message = 'Cuenta bloqueada por intentos fallidos.',
    ) {
        parent::__construct($message);
    }

    public function secondsRemaining(): int
    {
        return max(0, (int) now()->diffInSeconds($this->lockedUntil, false));
    }
}
