<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Tenancy\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Modelo de token de acceso personal con resolución del dueño SIN tenant scope.
 *
 * PROBLEMA QUE RESUELVE:
 * Durante un request autenticado, Sanctum resuelve al usuario dueño del token
 * (la relación polimórfica `tokenable`) ANTES de que el middleware `tenant`
 * haya establecido el TenantContext. Como User usa el trait BelongsToTenant,
 * su TenantScope aplica `WHERE FALSE` cuando no hay contexto (fail-secure),
 * el usuario "desaparece" y Sanctum responde 401 UNAUTHENTICATED incluso con
 * un token válido en su propio tenant.
 *
 * SOLUCIÓN:
 * La relación `tokenable` carga al usuario IGNORANDO el TenantScope. Esto es
 * seguro y NO debilita el aislamiento:
 *   - El token (tokenable_id) ya apunta a UN usuario único en toda la base;
 *     cargarlo sin scope solo recupera a su dueño legítimo, no permite saltar
 *     de tenant.
 *   - El callback Sanctum::authenticateAccessTokensUsing() en AppServiceProvider
 *     valida que el company_id del dueño coincida con el tenant del header
 *     X-Tenant. Esa es la barrera real del aislamiento de auth.
 *   - RLS de Postgres permanece como segunda barrera (ver ADR-0006).
 *
 * NOTA: la clase NO es `final` a propósito. Sanctum::actingAs() hace
 * Mockery::mock() sobre el modelo de token, y Mockery no puede mockear clases
 * final. Marcarla como final rompería todos los tests del proyecto que usan
 * actingAs().
 *
 * Se registra vía Sanctum::usePersonalAccessTokenModel() en AppServiceProvider.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /**
     * El dueño del token, resuelto sin el filtro de tenant.
     */
    public function tokenable(): MorphTo
    {
        return $this->morphTo('tokenable')
            ->withoutGlobalScope(TenantScope::class);
    }
}
