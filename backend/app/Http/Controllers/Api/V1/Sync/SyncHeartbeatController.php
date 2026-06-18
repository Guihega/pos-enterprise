<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Sync;

use App\Domain\Tenancy\Services\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * GET /api/v1/sync/heartbeat
 *
 * Verifica conectividad + auth y devuelve la hora del servidor para que
 * el cliente detecte desincronizacion de reloj (sec. 42.5) y confirme que
 * el token/tenant siguen vigentes (sec. 42.6: si el token fue revocado,
 * el middleware auth:sanctum responde 401 antes de llegar aqui).
 *
 * Respuesta:
 *   server_time: ISO 8601 Zulu (UTC). El cliente compara contra su reloj.
 *   tenant:      slug del tenant activo.
 *   user_uuid:   uuid del usuario autenticado.
 */
final class SyncHeartbeatController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $tenant = TenantContext::current();

        return response()->json([
            'server_time' => Carbon::now()->toIso8601ZuluString(),
            'tenant'      => $tenant?->slug,
            'user_uuid'   => $request->user()?->uuid,
        ]);
    }
}
