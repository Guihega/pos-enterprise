<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Token de recuperacion de password (flujo 57.6 del maestro).
 *
 * Tabla heredada de la migracion 000003 con esquema estandar de Laravel
 * adaptado a multi-tenant: PK compuesta (company_id, email), sin id
 * autoincremental y sin updated_at. La PK garantiza un solo token vivo
 * por usuario: emitir uno nuevo sobrescribe el anterior.
 *
 * El token se guarda hasheado; el valor en claro solo existe en la
 * respuesta HTTP de emision.
 */
final class PasswordResetToken extends Model
{
    use BelongsToTenant;

    protected $table = 'password_reset_tokens';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'email',
        'token',
        'created_at',
        'expires_at',
        'used_at',
        'created_by',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public function isUsable(): bool
    {
        return $this->used_at === null
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }
}
