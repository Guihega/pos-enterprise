<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Sync;

use App\Domain\Sync\Models\SyncDevice;
use App\Domain\Tenancy\Models\Branch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sync\SyncRegistrationRequest;
use App\Http\Resources\SyncDeviceResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * POST /api/v1/sync/registration (doc maestro 29.x, tabla 26.12).
 *
 * Registra o re-registra un dispositivo cliente. Idempotente por la
 * unique (company_id, device_id): un re-registro actualiza branch,
 * nombre, tipo y fingerprint sin duplicar, y reactiva el dispositivo.
 * Sin permiso especifico (patron del grupo sync: solo auth), igual que
 * batch/changes/heartbeat: son endpoints operados por el dispositivo.
 * El trio auth/devices/* del maestro (autorizacion/revocacion) es un
 * dominio distinto y queda DIFERIDO.
 */
final class SyncRegistrationController extends Controller
{
    public function __invoke(SyncRegistrationRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $branch = Branch::query()->where('uuid', $validated['branch_uuid'])->firstOrFail();

        $device = SyncDevice::query()->updateOrCreate(
            ['device_id' => $validated['device_id']],
            [
                'uuid' => (string) Str::uuid(),
                'branch_id' => $branch->id,
                'user_id' => $request->user()?->id,
                'name' => $validated['name'] ?? null,
                'type' => $validated['type'],
                'fingerprint' => $validated['fingerprint'] ?? null,
                'settings' => $validated['settings'] ?? [],
                'is_active' => true,
                'last_seen_at' => now(),
            ],
        );

        return response()->json(
            ['data' => new SyncDeviceResource($device)],
            $device->wasRecentlyCreated ? 201 : 200,
        );
    }
}
