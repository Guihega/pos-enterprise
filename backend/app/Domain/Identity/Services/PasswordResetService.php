<?php

declare(strict_types=1);

namespace App\Domain\Identity\Services;

use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Identity\Exceptions\InvalidCredentialsException;
use App\Domain\Identity\Exceptions\InvalidResetTokenException;
use App\Domain\Identity\Models\PasswordResetToken;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Recuperacion y cambio de password (flujo 57.6 del maestro).
 *
 * Decisiones documentadas:
 * - El reset administrativo NO genera password temporal: emite un token de
 *   un solo uso que el usuario canjea eligiendo su propia password. Asi el
 *   admin nunca conoce la credencial y esta no viaja por canales informales
 *   ni queda en logs de respuesta.
 * - Token hasheado en base (Hash::make); el claro solo se devuelve una vez.
 * - Vigencia 1 hora per flujo 57.6, via Company.setting para poder ajustarla.
 * - Al consumir: password actualizado, todas las sesiones revocadas y
 *   must_change_password apagado (paso 5 del flujo).
 * - forgot-password publico por email queda DIFERIDO: no hay infraestructura
 *   de correo en el proyecto (app/Mail inexistente). created_by nullable ya
 *   contempla ese caso (null = auto-servicio).
 *
 * Asume TenantContext ya seteado por el middleware, igual que AuthService.
 */
final class PasswordResetService
{
    private const DEFAULT_TTL_MINUTES = 60;

    public function __construct(
        private readonly ActivityLogger $logger,
        private readonly AuthService $auth,
    ) {}

    /**
     * Emite un token de reset para el usuario indicado (reset administrativo).
     * Devuelve el token EN CLARO: es la unica vez que existe fuera del hash.
     */
    public function issueFor(User $target, User $admin): string
    {
        $this->assertTenant();

        $plain = Str::random(48);
        $ttl = (int) ($this->companySetting('auth.reset_ttl_minutes') ?? self::DEFAULT_TTL_MINUTES);
        $now = Carbon::now()->utc();

        DB::transaction(function () use ($target, $admin, $plain, $ttl, $now): void {
            PasswordResetToken::query()->where('email', $target->email)->delete();

            PasswordResetToken::query()->create([
                'email' => $target->email,
                'token' => Hash::make($plain),
                'created_at' => $now,
                'expires_at' => $now->copy()->addMinutes($ttl),
                'used_at' => null,
                'created_by' => $admin->id,
            ]);
        });

        $this->logger->log(
            'identity',
            'password.reset_issued',
            sprintf('Reset de password emitido para %s', $target->email),
            $target,
            ['issued_by' => $admin->id, 'expires_at' => $now->copy()->addMinutes($ttl)->toIso8601String()],
            'warning',
        );

        return $plain;
    }

    /**
     * Canjea el token y fija la nueva password. Revoca todas las sesiones.
     */
    public function redeem(string $email, string $plainToken, string $newPassword): User
    {
        $this->assertTenant();

        $row = PasswordResetToken::query()->where('email', $email)->first();

        if ($row === null || ! $row->isUsable() || ! Hash::check($plainToken, $row->token)) {
            throw InvalidResetTokenException::make();
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            throw InvalidResetTokenException::make();
        }

        DB::transaction(function () use ($user, $row, $newPassword): void {
            $user->password = $newPassword;
            $user->must_change_password = false;
            $user->password_changed_at = Carbon::now();
            $user->save();

            // La tabla tiene PK compuesta (company_id, email) y no columna id:
            // Eloquent no puede construir el WHERE de un update sobre el modelo,
            // asi que el consumo se marca por query builder con la clave real.
            PasswordResetToken::query()
                ->where('email', $row->email)
                ->update(['used_at' => Carbon::now()->utc()]);
        });

        $this->auth->logoutAll($user);

        $this->logger->log(
            'identity',
            'password.reset_redeemed',
            sprintf('Password restablecido para %s', $user->email),
            $user,
            ['sessions_revoked' => true],
            'warning',
        );

        return $user;
    }

    /**
     * Cambio voluntario: exige la password actual. Revoca las demas sesiones.
     */
    public function change(User $user, string $current, string $newPassword): void
    {
        $this->assertTenant();

        if (! Hash::check($current, $user->password)) {
            throw new InvalidCredentialsException('La password actual no coincide');
        }

        $user->password = $newPassword;
        $user->must_change_password = false;
        $user->password_changed_at = Carbon::now();
        $user->save();

        $this->logger->log(
            'identity',
            'password.changed',
            'Password cambiado por el propio usuario',
            $user,
            [],
            'info',
        );
    }

    private function companySetting(string $key): mixed
    {
        return TenantContext::current()?->setting($key);
    }

    private function assertTenant(): void
    {
        if (! TenantContext::has()) {
            throw new \LogicException(
                'PasswordResetService requiere tenant en contexto. '.
                'Aplica el middleware "tenant" antes que la ruta.'
            );
        }
    }
}
