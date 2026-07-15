<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Authorization\Permissions;
use App\Domain\Sync\Models\SyncDevice;
use App\Http\Controllers\Controller;
use App\Http\Resources\SyncDeviceResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Gestion de dispositivos (maestro 29.1: GET/DELETE auth/devices).
 *
 * Dominio de autorizacion, distinto del registro tecnico operado por
 * el dispositivo (POST /sync/registration, ver docblock de
 * SyncRegistrationController). POST /auth/devices/register queda
 * DIFERIDO: funcionalmente cubierto por sync/registration.
 *
 * Desautorizar = is_active false (soft, conserva historial de sync).
 * El enforcement (dispositivo inactivo rechazado en sync) se
 * implementa en el slice de SYNC_DEVICE_UNREGISTERED.
 */
final class DevicesController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless((bool) $request->user()?->can(Permissions::DEVICE_VIEW), 403);

        $query = SyncDevice::query()->orderByDesc('last_seen_at');

        if ($request->query('active') !== null) {
            $query->where('is_active', $request->boolean('active'));
        }

        return SyncDeviceResource::collection($query->get());
    }

    public function destroy(Request $request, SyncDevice $device): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::DEVICE_REVOKE), 403);

        $device->update(['is_active' => false]);

        return response()->json(['data' => new SyncDeviceResource($device->fresh())]);
    }
}
