<?php

declare(strict_types=1);

namespace App\Domain\Identity\Services;

use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Identity\Exceptions\AccountInactiveException;
use App\Domain\Identity\Exceptions\AccountLockedException;
use App\Domain\Identity\Exceptions\InvalidCredentialsException;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\Hash;

/**
 * Punto único para todas las operaciones de autenticación.
 *
 * Encapsula:
 *   - Lookup del usuario en el tenant correcto.
 *   - Verificación de password con timing-safe.
 *   - Manejo de bloqueo y reintentos.
 *   - Emisión y revocación de tokens Sanctum.
 *   - Auditoría de login (IP, UA, device).
 *
 * Excepciones (en orden de comprobación):
 *   - InvalidCredentialsException: credenciales inválidas (genérico, NO
 *     revela si el email existe o no — anti-enumeración).
 *   - AccountInactiveException: usuario desactivado por admin.
 *   - AccountLockedException: bloqueo por intentos fallidos.
 *
 * IMPORTANTE: este servicio asume que TenantContext::set($company) ya fue
 * ejecutado por el middleware. No es responsabilidad del servicio resolver
 * el tenant; se centraliza en el middleware (Capa de transporte).
 */
final class AuthService
{
    public function __construct(
        private readonly ActivityLogger $logger,
    ) {}

    /**
     * Login por email + password. Devuelve el token recién emitido si OK,
     * lanza excepción específica si no.
     *
     * @param  array{ip?: string|null, user_agent?: string|null, device_id?: string|null, token_name?: string|null}  $context
     */
    public function login(string $email, string $password, array $context = []): LoginResult
    {
        if (! TenantContext::has()) {
            throw new \LogicException(
                'AuthService::login requiere tenant en contexto. '.
                'Aplica el middleware "tenant" antes que la ruta de login.'
            );
        }

        // Lookup en el tenant actual (gracias al global scope)
        $user = User::query()
            ->where('email', $email)
            ->first();

        // Anti-enumeración: si no existe, hacemos un dummy hash para igualar
        // tiempos de respuesta y luego lanzamos excepción genérica.
        if ($user === null) {
            $this->dummyPasswordCheck();
            throw new InvalidCredentialsException;
        }

        // Bloqueo por intentos previos
        if ($user->isLocked()) {
            throw new AccountLockedException($user->locked_until);
        }

        // Cuenta deshabilitada
        if (! $user->is_active) {
            throw new AccountInactiveException;
        }

        // Verificación timing-safe de password
        if (! Hash::check($password, $user->password)) {
            $user->registerFailedLogin();

            // RN-176: login fallido con IP y user agent. RN-174 (logs de
            // seguridad separados) cumplido via log_name=security sin tabla
            // aparte. Solo usuarios existentes: el intento contra email
            // inexistente no se audita (anti-enumeracion: registrar el email
            // probado seria filtrar su existencia; diferido documentado).
            $this->logger->log(
                logName: 'security',
                event: 'login.failed',
                description: 'Intento de login fallido',
                subject: $user,
                properties: ['reason' => 'invalid_password'],
                severity: 'warning',
                deviceId: $context['device_id'] ?? null,
                ip: $context['ip'] ?? null,
                userAgent: $context['user_agent'] ?? null,
            );

            // Si este intento fue el que la bloqueó, devolvemos la excepción
            // específica de bloqueo en lugar de invalid-credentials para que
            // el cliente pueda mostrar el mensaje correcto.
            if ($user->fresh()->isLocked()) {
                throw new AccountLockedException($user->fresh()->locked_until);
            }

            throw new InvalidCredentialsException;
        }

        // Login OK: limpia contadores y registra metadatos.
        $user->registerSuccessfulLogin(
            ip: $context['ip'] ?? '0.0.0.0',
            userAgent: $context['user_agent'] ?? null,
            deviceId: $context['device_id'] ?? null,
        );

        // Emitir token Sanctum.
        $tokenName = $context['token_name'] ?? 'pos-session';
        $token = $user->createToken($tokenName, ['*']);

        // Eager-load las relaciones que UserResource consume. Evita
        // queries extra en el response del login (sin esto, lazy-load
        // de defaultBranch y defaultWarehouse generaria 2 queries
        // adicionales por request).
        $user->load('defaultBranch.defaultWarehouse', 'branches', 'roles');

        return new LoginResult(
            user: $user,
            token: $token,
        );
    }

    /**
     * Revoca el token actual (logout single-session).
     */
    public function logout(User $user): void
    {
        $current = $user->currentAccessToken();
        if ($current !== null) {
            $current->delete();
        }
    }

    /**
     * Revoca TODOS los tokens del usuario (logout-all-devices).
     */
    public function logoutAll(User $user): int
    {
        $count = $user->tokens()->count();
        $user->tokens()->delete();

        return $count;
    }

    /**
     * Verifica el PIN supervisor del usuario.
     * Devuelve true si OK, false si no (con bloqueo automático tras N fallos).
     */
    public function verifySupervisorPin(User $supervisor, string $pin): bool
    {
        return $supervisor->verifyPin($pin);
    }

    /**
     * Hash dummy para igualar tiempos cuando el email no existe.
     * Previene enumeración de usuarios por timing attack.
     */
    private function dummyPasswordCheck(): void
    {
        Hash::check('dummy-password', '$2y$12$ZxqL2OTH7LjD3oKM5iG7jOFKBhiLLLD3oJBqzTzr4w3W7c2k/Pdtq');
    }
}
