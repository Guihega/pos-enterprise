<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Authorization\Roles;
use App\Domain\Inventory\Models\Batch;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Console\Command;

/**
 * RN-195: detecta lotes proximos a caducar (inventory.expiring_soon,
 * WARNING) y lotes ya caducados (inventory.expired, CRITICAL) y
 * notifica a ALMACEN de la sucursal del lote (doc maestro: "Producto a
 * punto de caducar notifica a almacen", tipos de la seccion de
 * notificaciones).
 *
 * Corre por scheduler. Scan multi-tenant identico a DetectLostTransfers:
 * itera companies y TenantContext::runAs acota los lotes.
 *
 * Umbral: el maestro lo define "configurable dias" sin fijar valor;
 * estandar adoptado 30 dias, configurable via --days=.
 *
 * Idempotente (patron EX-043): la caducidad no revierte; cada lote se
 * alerta una sola vez por tipo via whereNull(expiring_alerted_at /
 * expired_alerted_at) + marca al notificar. Solo lotes con remanente
 * (quantity > 0): alertar lotes agotados es ruido operativo. Un lote
 * que entra directo a caducado sin haber sido alertado de proximidad
 * solo genera inventory.expired.
 */
class DetectExpiringBatches extends Command
{
    /** Dias de anticipacion default para inventory.expiring_soon (RN-195). */
    public const DEFAULT_EXPIRING_DAYS = 30;

    protected $signature = 'batches:detect-expiring {--days=30 : Dias de anticipacion para lote por caducar}';

    protected $description = 'Detecta lotes por caducar y caducados y notifica a almacen (RN-195)';

    public function handle(NotificationService $notifications): int
    {
        $days = max(1, (int) $this->option('days'));
        $totalExpiring = 0;
        $totalExpired = 0;

        foreach (Company::query()->get() as $company) {
            [$expiring, $expired] = TenantContext::runAs($company, function () use ($notifications, $days): array {
                $today = now()->toDateString();
                $limit = now()->addDays($days)->toDateString();

                // Caducados primero: un lote vencido no debe recibir ademas "por caducar".
                $expiredBatches = Batch::query()
                    ->with(['product', 'branch'])
                    ->where('quantity', '>', 0)
                    ->whereNotNull('expiration_date')
                    ->where('expiration_date', '<', $today)
                    ->whereNull('expired_alerted_at')
                    ->get();

                $expiredCount = 0;
                foreach ($expiredBatches as $batch) {
                    $this->notifyBatch($notifications, $batch, 'inventory.expired', Notification::SEVERITY_CRITICAL);
                    $batch->update([
                        'expired_alerted_at' => now(),
                        'expiring_alerted_at' => $batch->expiring_alerted_at ?? now(),
                    ]);
                    $expiredCount++;
                }

                $expiringBatches = Batch::query()
                    ->with(['product', 'branch'])
                    ->where('quantity', '>', 0)
                    ->whereNotNull('expiration_date')
                    ->where('expiration_date', '>=', $today)
                    ->where('expiration_date', '<=', $limit)
                    ->whereNull('expiring_alerted_at')
                    ->get();

                $expiringCount = 0;
                foreach ($expiringBatches as $batch) {
                    $this->notifyBatch($notifications, $batch, 'inventory.expiring_soon', Notification::SEVERITY_WARNING);
                    $batch->update(['expiring_alerted_at' => now()]);
                    $expiringCount++;
                }

                return [$expiringCount, $expiredCount];
            });

            $totalExpiring += $expiring;
            $totalExpired += $expired;
        }

        $this->info("Lotes por caducar alertados: {$totalExpiring}. Lotes caducados alertados: {$totalExpired}.");

        return self::SUCCESS;
    }

    private function notifyBatch(NotificationService $notifications, Batch $batch, string $type, string $severity): void
    {
        $recipients = $notifications->usersWithRolesForBranch([Roles::ALMACEN], $batch->branch);

        foreach ($recipients as $user) {
            $notifications->notify(
                recipient: $user,
                type: $type,
                data: [
                    'batch_uuid' => $batch->uuid,
                    'lot_number' => $batch->lot_number,
                    'product_uuid' => $batch->product->uuid,
                    'product_name' => $batch->product->name,
                    'branch_uuid' => $batch->branch->uuid,
                    'expiration_date' => $batch->expiration_date?->toDateString(),
                    'quantity' => (float) $batch->quantity,
                ],
                severity: $severity,
            );
        }
    }
}
