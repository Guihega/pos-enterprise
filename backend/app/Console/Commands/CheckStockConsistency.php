<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Authorization\Roles;
use App\Domain\Inventory\Models\Stock;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Console\Command;

final class CheckStockConsistency extends Command
{
    protected $signature = 'stock:check-consistency';

    protected $description = 'Detecta inconsistencias de stock (EX-042: reservado mayor que existencia) y alerta a admins';

    public function handle(NotificationService $notifications): int
    {
        $totalAlerts = 0;

        foreach (Company::query()->get() as $company) {
            $totalAlerts += (int) TenantContext::runAs($company, function () use ($notifications): int {
                $inconsistent = Stock::query()
                    ->whereColumn('quantity_reserved', '>', 'quantity_on_hand')
                    ->get();

                if ($inconsistent->isEmpty()) {
                    return 0;
                }

                $admins = $notifications->usersWithRoles([Roles::ADMIN]);
                $count = 0;

                foreach ($inconsistent as $stock) {
                    foreach ($admins as $admin) {
                        $notifications->notify(
                            $admin,
                            'stock.reserved_inconsistency',
                            [
                                'stock_id' => $stock->id,
                                'product_id' => $stock->product_id,
                                'warehouse_id' => $stock->warehouse_id,
                                'quantity_on_hand' => (float) $stock->quantity_on_hand,
                                'quantity_reserved' => (float) $stock->quantity_reserved,
                            ],
                            Notification::SEVERITY_CRITICAL,
                        );
                    }

                    $count++;
                }

                return $count;
            });
        }

        $this->info("Inconsistencias de stock detectadas: {$totalAlerts}");

        return self::SUCCESS;
    }
}
