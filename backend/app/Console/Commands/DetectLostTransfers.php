<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Authorization\Roles;
use App\Domain\Inventory\Models\Transfer;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Console\Command;

/**
 * EX-043: detecta transferencias "perdidas" (sent pero nunca received tras el
 * TTL) y escala a admin (doc maestro 13.3 EX-043).
 *
 * Corre por scheduler. El scan es multi-tenant: itera todas las companies y,
 * dentro de TenantContext::runAs, el TenantScope acota las transferencias y
 * BelongsToTenant asigna el company_id correcto a cada notificacion.
 *
 * Idempotente: cada transferencia perdida marca lost_alerted_at al notificar,
 * y el filtro whereNull('lost_alerted_at') evita repetir la alerta en corridas
 * posteriores mientras siga en estado sent.
 */
class DetectLostTransfers extends Command
{
    /**
     * Dias que una transferencia puede permanecer en sent antes de
     * considerarse perdida (EX-043).
     */
    public const LOST_TRANSFER_TTL_DAYS = 30;

    protected $signature = 'transfers:detect-lost';

    protected $description = 'Detecta transferencias perdidas (sent sin received tras el TTL) y alerta al admin (EX-043)';

    public function handle(NotificationService $notifications): int
    {
        $cutoff = now()->subDays(self::LOST_TRANSFER_TTL_DAYS);
        $totalAlerted = 0;

        foreach (Company::query()->get() as $company) {
            $alerted = TenantContext::runAs($company, function () use ($notifications, $cutoff): int {
                $lost = Transfer::query()
                    ->where('status', Transfer::STATUS_SENT)
                    ->where('sent_at', '<', $cutoff)
                    ->whereNull('lost_alerted_at')
                    ->get();

                $admins = $notifications->usersWithRoles([Roles::ADMIN]);
                $count = 0;

                foreach ($lost as $transfer) {
                    foreach ($admins as $admin) {
                        $notifications->notify(
                            recipient: $admin,
                            type: 'transfer.lost',
                            data: [
                                'transfer_id' => $transfer->id,
                                'transfer_uuid' => $transfer->uuid,
                                'folio' => $transfer->folio,
                                'from_branch_id' => $transfer->from_branch_id,
                                'to_branch_id' => $transfer->to_branch_id,
                                'sent_at' => $transfer->sent_at?->toIso8601String(),
                            ],
                            severity: Notification::SEVERITY_WARNING,
                        );
                    }

                    $transfer->update(['lost_alerted_at' => now()]);
                    $count++;
                }

                return $count;
            });

            $totalAlerted += $alerted;
        }

        $this->info("Transferencias perdidas alertadas: {$totalAlerted}.");

        return self::SUCCESS;
    }
}
