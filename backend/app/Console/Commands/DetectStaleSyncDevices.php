<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Authorization\Roles;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Sync\Models\SyncDevice;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Console\Command;

/**
 * RN-194: "Sync caida >2h notifica a admin | Automatica". Detecta
 * dispositivos activos cuyo last_seen_at supera el umbral y notifica
 * a ADMIN tenant-wide (usersWithRoles, igual que DetectLostTransfers).
 *
 * Tipo adoptado y documentado: sync.device_stale (el catalogo de
 * notificaciones del maestro no define tipo para RN-194); severidad
 * WARNING. Un dispositivo sin last_seen_at nunca reporto: no se alerta
 * (no hay caida que detectar, solo registro sin uso).
 *
 * Idempotente patron EX-042 (estado reversible): marca stale_alerted_at
 * al notificar y filtra whereNull; el heartbeat la limpia cuando el
 * dispositivo vuelve, rearmando la alerta para caidas futuras.
 *
 * Umbral comparado contra timestamps generados en PHP (now()->subHours),
 * consistente con la convencion de serializacion del proyecto.
 */
class DetectStaleSyncDevices extends Command
{
    /** Horas sin heartbeat para considerar caida la sync (RN-194). */
    public const STALE_THRESHOLD_HOURS = 2;

    protected $signature = 'sync:detect-stale {--hours=2 : Horas sin heartbeat para alertar}';

    protected $description = 'Detecta dispositivos con sync caida >2h y alerta al admin (RN-194)';

    public function handle(NotificationService $notifications): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $totalAlerted = 0;

        foreach (Company::query()->get() as $company) {
            $alerted = TenantContext::runAs($company, function () use ($notifications, $hours): int {
                $cutoff = now()->subHours($hours);

                $stale = SyncDevice::query()
                    ->active()
                    ->whereNotNull('last_seen_at')
                    ->where('last_seen_at', '<', $cutoff)
                    ->whereNull('stale_alerted_at')
                    ->with('branch')
                    ->get();

                $admins = $notifications->usersWithRoles([Roles::ADMIN]);
                $count = 0;

                foreach ($stale as $device) {
                    foreach ($admins as $admin) {
                        $notifications->notify(
                            recipient: $admin,
                            type: 'sync.device_stale',
                            data: [
                                'device_uuid' => $device->uuid,
                                'device_id' => $device->device_id,
                                'name' => $device->name,
                                'type' => $device->type,
                                'branch_uuid' => $device->branch->uuid,
                                'last_seen_at' => $device->last_seen_at->toIso8601String(),
                                'threshold_hours' => $hours,
                            ],
                            severity: Notification::SEVERITY_WARNING,
                        );
                    }

                    $device->update(['stale_alerted_at' => now()]);
                    $count++;
                }

                return $count;
            });

            $totalAlerted += $alerted;
        }

        $this->info("Dispositivos con sync caida alertados: {$totalAlerted}.");

        return self::SUCCESS;
    }
}
