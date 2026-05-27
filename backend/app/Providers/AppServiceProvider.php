<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Identity\Models\PersonalAccessToken;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Sanctum 4 NO autocarga sus migraciones (cambió respecto a v3),
        // por lo que no necesitamos ignoreMigrations() ni nada similar:
        // nuestra migración custom en database/migrations/ se ejecuta y
        // basta con eso.
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Sanctum usa nuestro modelo de token, cuya relación tokenable carga
        // al usuario SIN el TenantScope. Necesario porque el guard de Sanctum
        // resuelve el tokenable ANTES de que el middleware 'tenant' establezca
        // el contexto; sin esto, el TenantScope aplica WHERE FALSE y todo
        // request autenticado devuelve 401 aun con un token válido.
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        /*
        |----------------------------------------------------------------------
        | Aislamiento de tenant a nivel de autenticación de token.
        |----------------------------------------------------------------------
        |
        | CONTRATO: un token solo autentica si su usuario dueño pertenece al
        | MISMO tenant que el indicado por la cabecera X-Tenant del request.
        |
        | POR QUÉ AQUÍ: este callback corre durante la validación del guard de
        | Sanctum, que sucede ANTES de que el middleware 'tenant' establezca el
        | TenantContext (verificado empíricamente: TenantContext::has() es false
        | en este punto). Por eso NO comparamos contra el contexto, sino que
        | resolvemos el tenant esperado directamente desde la cabecera X-Tenant.
        |
        | SEPARACIÓN DE CAPAS:
        |   - Tenant inexistente (header con un slug que no resuelve a ningún
        |     Company) NO es responsabilidad de este callback: lo maneja el
        |     middleware EnsureTenantContext con 400 TENANT_NOT_RESOLVED. En
        |     ese caso devolvemos true para no usurpar la respuesta correcta
        |     del middleware. No se filtra nada: el middleware aborta el
        |     request antes de llegar al controlador.
        |   - Sin cabecera X-Tenant: tampoco es nuestra responsabilidad; el
        |     middleware decide (subdominio o 400). Devolvemos true por la
        |     misma razón.
        |   - Token cuyo dueño es de OTRO tenant que SÍ existe: AQUÍ sí
        |     rechazamos con 401 — es el corazón del aislamiento.
        |
        | QUÉ CIERRA:
        |   - Token de A + X-Tenant: A           -> coinciden            -> 200.
        |   - Token de A + X-Tenant: B           -> no coinciden         -> 401.
        |   - Token revocado                     -> $isValid es false    -> 401.
        |   - X-Tenant inexistente               -> middleware responde  -> 400.
        |   - Sin X-Tenant                       -> middleware responde  -> 400.
        |
        | El aislamiento de DATOS sigue garantizado por el TenantScope (en las
        | demás consultas, una vez establecido el contexto) y por RLS en
        | Postgres como segunda barrera (ADR-0006). Esta regla añade el
        | aislamiento de la AUTENTICACIÓN misma.
        */
        Sanctum::authenticateAccessTokensUsing(
            function (PersonalAccessToken $accessToken, bool $isValid): bool {
                // Si Sanctum ya lo invalidó (hash, expiración, token revocado),
                // respetamos esa decisión.
                if (! $isValid) {
                    return false;
                }

                $identifier = request()->header('X-Tenant');

                // Sin cabecera: no es nuestra capa. El middleware 'tenant' se
                // encargará (responderá 400 o resolverá por subdominio).
                if (! is_string($identifier) || $identifier === '') {
                    return true;
                }

                $expectedTenant = Company::findByIdentifier($identifier);

                // Tenant inexistente: tampoco es nuestra capa. Dejamos pasar
                // para que el middleware 'tenant' responda 400 TENANT_NOT_RESOLVED.
                if ($expectedTenant === null) {
                    return true;
                }

                // El tenant existe. Ahora sí: el token vale solo si su dueño
                // pertenece a ese tenant.
                $owner = $accessToken->tokenable;
                if ($owner === null || ! isset($owner->company_id)) {
                    return false;
                }

                return (int) $owner->company_id === (int) $expectedTenant->id;
            }
        );
    }
}
