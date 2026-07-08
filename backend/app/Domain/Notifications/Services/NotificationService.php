<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Services;

use App\Domain\Authorization\Roles;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Tenancy\Models\Branch;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Crea notificaciones in-app / multicanal (doc maestro 26.14 y 11.9).
 *
 * El canal in-app siempre persiste en la tabla notifications. Los canales
 * externos (email/sms/push/whatsapp) son puntos de extension: por ahora se
 * registran en la columna channels para trazabilidad, su despacho real es
 * integracion de produccion.
 *
 * company_id lo asigna el trait BelongsToTenant en creating desde el
 * TenantContext activo; el destinatario es un User de una sucursal pero la
 * notificacion pertenece al tenant.
 */
final class NotificationService
{
    public const CHANNEL_IN_APP = 'in-app';

    /**
     * Crea una notificacion in-app para un destinatario.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $channels
     */
    public function notify(
        User $recipient,
        string $type,
        array $data,
        string $severity = Notification::SEVERITY_INFO,
        array $channels = [self::CHANNEL_IN_APP],
        ?Carbon $expiresAt = null,
    ): Notification {
        if (! in_array(self::CHANNEL_IN_APP, $channels, true)) {
            $channels[] = self::CHANNEL_IN_APP;
        }

        return Notification::query()->create([
            'uuid' => (string) Str::uuid(),
            'type' => $type,
            'notifiable_type' => $recipient->getMorphClass(),
            'notifiable_id' => $recipient->getKey(),
            'data' => $data,
            'channels' => array_values($channels),
            'severity' => $severity,
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * Usuarios con alguno de los roles dados, ASIGNADOS a una sucursal
     * (via user_branches). Base de las reglas de notificacion por sucursal
     * (RN-190 almacen/gerente, RN-191 gerente, RN-195 almacen).
     *
     * @param  list<string>  $roles
     * @return Collection<int, User>
     */
    public function usersWithRolesForBranch(array $roles, Branch $branch): Collection
    {
        return User::query()
            ->role($roles)
            ->whereHas('branches', fn (BuilderContract $q) => $q->where('branches.id', $branch->id))
            ->get();
    }

    /**
     * Usuarios con alguno de los roles dados en todo el tenant (sin filtro de
     * sucursal). Base de las reglas globales (RN-193/194/196 admin, RN-197
     * auditor, RN-198 cobranza).
     *
     * @param  list<string>  $roles
     * @return Collection<int, User>
     */
    public function usersWithRoles(array $roles): Collection
    {
        return User::query()->role($roles)->get();
    }

    /**
     * Destinatarios de RN-190: ALMACEN y GERENTE de la sucursal del stock.
     *
     * @return Collection<int, User>
     */
    public function warehouseAndManagerUsersForBranch(Branch $branch): Collection
    {
        return $this->usersWithRolesForBranch([Roles::ALMACEN, Roles::GERENTE], $branch);
    }
}
