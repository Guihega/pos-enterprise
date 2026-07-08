<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Notifications;

use App\Domain\Notifications\Models\Notification;
use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Notificaciones in-app del usuario autenticado (doc maestro 26.14 y 11.9).
 *
 * El acceso es por ownership: cada usuario ve y marca solo las suyas
 * (notifiable = el propio User). No hay permiso RBAC porque el maestro no lo
 * define; se aplica el estandar de propiedad.
 */
class NotificationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $perPage = min((int) $request->query('per_page', 50), 200);

        $query = Notification::query()
            ->forNotifiable($user)
            ->unread()
            ->latest('created_at');

        return NotificationResource::collection($query->paginate($perPage));
    }

    public function read(Request $request, Notification $notification): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        // Ownership estricto: solo el destinatario puede marcarla leida.
        abort_unless(
            $notification->notifiable_type === $user->getMorphClass()
                && $notification->notifiable_id === $user->getKey(),
            403
        );

        $notification->markAsRead();

        return response()->json(['data' => new NotificationResource($notification)]);
    }
}
