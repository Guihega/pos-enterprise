<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Middleware;

use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resuelve el tenant del request y lo establece en TenantContext.
 *
 * Estrategias de resolución, en orden de prioridad:
 *   1. Subdominio: {slug}.pos.example.com
 *   2. Header: X-Tenant: {uuid|slug}
 *   3. Claim del JWT/Sanctum token (si el usuario está autenticado y su token
 *      lleva el tenant — útil para apps móviles).
 *
 * Si no se puede resolver:
 *   - 400 TENANT_NOT_RESOLVED en producción.
 *   - En desarrollo (TENANT_FALLBACK_TO_DEFAULT=true) cae al primer tenant
 *     activo, útil para pruebas exploratorias en psql / curl.
 *
 * Si el tenant existe pero está suspended/cancelled/deleted:
 *   - 402 TENANT_SUSPENDED  (modo read-only no es responsabilidad de este
 *     middleware; lo decide otro posterior).
 *
 * Al final del request, el contexto se limpia automáticamente vía terminate().
 */
final class EnsureTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->resolveTenant($request);

        if ($tenant === null) {
            return $this->errorResponse(
                code: 'TENANT_NOT_RESOLVED',
                message: 'No se pudo determinar el tenant del request. '.
                         'Asegúrate de proveer subdominio, header X-Tenant o token con tenant.',
                status: 400,
            );
        }

        if (! $tenant->isOperational()) {
            return $this->errorResponse(
                code: 'TENANT_SUSPENDED',
                message: 'La cuenta está suspendida o cancelada.',
                status: 402,
                details: [
                    'status' => $tenant->status,
                    'suspension_reason' => $tenant->suspension_reason,
                ],
            );
        }

        TenantContext::set($tenant);

        // Adjuntar tenant al request para uso downstream.
        $request->attributes->set('tenant', $tenant);

        return $next($request);
    }

    /**
     * Limpia el contexto al final del request, gane o pierda.
     */
    public function terminate(Request $request, Response $response): void
    {
        TenantContext::forget();

        // Resetear el team_id en el resolver de Spatie. El nuestro lee del
        // TenantContext, pero si alguien usó setPermissionsTeamId() para
        // override, lo limpiamos aquí.
        if (app()->bound(\Spatie\Permission\Contracts\PermissionsTeamResolver::class)) {
            app(\Spatie\Permission\Contracts\PermissionsTeamResolver::class)
                ->setPermissionsTeamId(null);
        }
    }

    /**
     * Aplica las estrategias de resolución en orden y devuelve el primer match.
     *
     * Distinción importante:
     *   - Si el cliente NO envió ninguna pista de tenant (header vacío,
     *     subdominio neutro): podemos caer al fallback en desarrollo.
     *   - Si el cliente SÍ envió pista pero no resolvió (header con un slug
     *     inexistente, subdominio inválido): NUNCA caemos al fallback,
     *     devolvemos null y el middleware responde 400. Esto evita que
     *     un tenant ataque a otro mandando headers basura y aterrizando
     *     en otra cuenta.
     */
    private function resolveTenant(Request $request): ?Company
    {
        $hintProvided = false;

        // 1. Subdominio
        $tenant = $this->resolveBySubdomain($request, $hintProvided);
        if ($tenant !== null) {
            return $tenant;
        }

        // 2. Header X-Tenant
        $tenant = $this->resolveByHeader($request, $hintProvided);
        if ($tenant !== null) {
            return $tenant;
        }

        // 3. Token / usuario autenticado. SOLO si NO se proveyó pista por
        // subdominio/header. Si el cliente dio una pista (p. ej. X-Tenant con
        // un slug que no existe), NO debemos "rescatar" el tenant desde el
        // usuario autenticado: eso permitiría que un token de A aterrice en
        // su propio tenant aunque el header apuntara a otra cosa (basura o
        // ataque). En presencia de pista inválida → null → 400 (lo enforza
        // el bloque siguiente y la rama final de este método).
        if (! $hintProvided && $tenant = $this->resolveByAuthenticatedUser($request)) {
            return $tenant;
        }

        // Fallback de desarrollo SOLO si el cliente no dio ninguna pista.
        // Si dio pistas pero ninguna resolvió → null → 400.
        if (! $hintProvided && config('tenancy.fallback_to_default', false)) {
            return Company::query()
                ->whereIn('status', [Company::STATUS_ACTIVE, Company::STATUS_TRIAL])
                ->orderBy('id')
                ->first();
        }

        return null;
    }

    private function resolveBySubdomain(Request $request, bool &$hintProvided): ?Company
    {
        $host = $request->getHost();
        $domain = config('tenancy.domain');

        if (! $domain || ! str_ends_with($host, ".{$domain}")) {
            return null;  // No se intenta resolver por subdominio: no es una "pista"
        }

        $subdomain = substr($host, 0, -strlen(".{$domain}"));

        // Subdominios reservados (no son tenants, no es pista)
        if (in_array($subdomain, ['www', 'api', 'admin', 'app'], true)) {
            return null;
        }

        // Sí es un subdominio candidato: marcamos como pista provista.
        $hintProvided = true;

        return Company::findByIdentifier($subdomain);
    }

    private function resolveByHeader(Request $request, bool &$hintProvided): ?Company
    {
        $headerName = config('tenancy.header_name', 'X-Tenant');
        $value = $request->header($headerName);

        if (! $value) {
            return null;
        }

        // El cliente sí mandó el header → es una pista explícita.
        $hintProvided = true;

        return Company::findByIdentifier($value);
    }

    private function resolveByAuthenticatedUser(Request $request): ?Company
    {
        $user = $request->user();

        if (! $user || ! isset($user->company_id)) {
            return null;
        }

        return Company::find($user->company_id);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function errorResponse(
        string $code,
        string $message,
        int $status,
        array $details = [],
    ): JsonResponse {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
                'request_id' => request()->header('X-Request-Id') ?? request()->headers->get('X-Request-ID'),
                'timestamp' => now()->toIso8601String(),
            ],
        ], $status);
    }
}
