<?php

declare(strict_types=1);

namespace App\Domain\Sync\Exceptions;

use RuntimeException;

/**
 * Dispositivo revocado o no registrado intenta operar sync.
 *
 * Catalogo de errores del maestro (28.7): SYNC_DEVICE_UNREGISTERED.
 * Cubre ambos casos en batch: device_id desconocido (nunca registrado)
 * y dispositivo desautorizado via DELETE /auth/devices/{uuid}
 * (is_active = false). El flujo legitimo siempre pasa por
 * POST /sync/registration primero.
 */
final class DeviceRevokedException extends RuntimeException
{
    public static function forDevice(string $deviceId): self
    {
        return new self(
            "Dispositivo {$deviceId} no registrado o desautorizado."
        );
    }
}
