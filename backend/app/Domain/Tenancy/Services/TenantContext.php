<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Exceptions\NoTenantContextException;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Punto único de verdad sobre el tenant actual del request/job.
 *
 * Reemplaza cualquier intento de leer "el tenant" desde stores ad-hoc
 * (request, app instance, sesión). Usar SIEMPRE este servicio.
 *
 * Ciclo de vida:
 *
 *  1. Middleware HTTP (EnsureTenantContext) llama set() al inicio del request
 *     y forget() al final.
 *  2. Jobs en cola que necesiten contexto tenant llaman set() al iniciar y
 *     forget() al terminar.
 *
 *     TODO(deuda-9): extraer este patron set()/forget() a un trait
 *     TenantAwareJob (o middleware de job) cuando exista el primer job
 *     en cola que cruce el boundary de tenant. Hoy no hay jobs en el
 *     proyecto, por lo que crear el trait ahora seria infra especulativa
 *     sin consumidor. El criterio de activacion es: al implementar el
 *     primer ShouldQueue que dependa del TenantContext.
 *
 *     TODO(deuda-10): bajo Laravel Octane el proceso PHP se reutiliza
 *     entre requests, por lo que el estado estatico ($current,
 *     $superAdminMode) NO se limpia solo y podria filtrarse de un
 *     tenant a otro (leakage cross-tenant). Al instalar Octane hay que
 *     registrar un listener de RequestReceived (o RequestTerminated)
 *     que llame TenantContext::forget(). Hoy corremos php-fpm (proceso
 *     por request, estado limpio automaticamente), asi que no aplica.
 *     Criterio de activacion: al instalar laravel/octane.
 *  3. Tests llaman set() en setUp y forget() en tearDown, o usan
 *     `actingAsTenant($company)` del helper de tests.
 *
 * Cada llamada a set() también establece la variable de sesión Postgres
 * `app.current_tenant_id` que alimenta a las políticas RLS (ver ADR-0006).
 */
final class TenantContext
{
    private static ?Company $current = null;

    private static bool $superAdminMode = false;

    /**
     * Establece el tenant actual.
     */
    public static function set(Company $company): void
    {
        if (! $company->isOperational()) {
            // Suspendido / cancelado / deleted: lo dejamos pasar al runtime
            // pero el middleware de status decide si rechaza el request o lo
            // pone en read-only. Aquí no es responsabilidad nuestra.
        }

        self::$current = $company;

        // Sincroniza con Postgres para que RLS funcione.
        DB::statement('SELECT set_config(?, ?, false)', [
            'app.current_tenant_id',
            (string) $company->id,
        ]);
    }

    /**
     * Olvida el tenant actual. Llamar al final de un request o job.
     */
    public static function forget(): void
    {
        self::$current = null;
        self::$superAdminMode = false;

        DB::statement("SELECT set_config('app.current_tenant_id', '0', false)");
        DB::statement("SELECT set_config('app.is_super_admin', 'false', false)");
    }

    /**
     * Devuelve el tenant actual, o null si no hay contexto.
     */
    public static function current(): ?Company
    {
        return self::$current;
    }

    /**
     * Devuelve el ID del tenant actual o lanza si no hay contexto.
     */
    public static function id(): int
    {
        if (self::$current === null) {
            throw new NoTenantContextException(
                'Se intentó acceder al ID del tenant sin contexto establecido. '.
                'Asegúrate de que el middleware EnsureTenantContext está aplicado a la ruta '.
                'o llama TenantContext::set($company) explícitamente en jobs/tests.'
            );
        }

        return self::$current->id;
    }

    /**
     * @return bool True si hay un tenant activo en el contexto.
     */
    public static function has(): bool
    {
        return self::$current !== null;
    }

    /**
     * Activa modo super_admin: permite que las políticas RLS de bypass se
     * apliquen. Solo debe usarse en panel administrativo del SaaS.
     *
     * Cualquier query subsiguiente NO se filtrará por tenant.
     */
    public static function enableSuperAdminMode(): void
    {
        self::$superAdminMode = true;
        DB::statement("SELECT set_config('app.is_super_admin', 'true', false)");
    }

    public static function isSuperAdmin(): bool
    {
        return self::$superAdminMode;
    }

    /**
     * Ejecuta un closure dentro del contexto de un tenant específico,
     * restaurando el contexto previo al terminar.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function runAs(Company $company, callable $callback): mixed
    {
        $previous = self::$current;
        $previousSuperAdmin = self::$superAdminMode;

        try {
            self::set($company);

            return $callback();
        } finally {
            if ($previous !== null) {
                self::set($previous);
                if ($previousSuperAdmin) {
                    self::enableSuperAdminMode();
                }
            } else {
                self::forget();
            }
        }
    }
}
