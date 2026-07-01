<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Reports\Services\ConsolidatedReportService;
use Illuminate\Console\Command;

/**
 * Refresca las vistas materializadas de reportes consolidados (doc maestro
 * 46.6). En produccion lo orquesta pg_cron cada 15 min; este comando es el
 * sustituto invocable en dev/app y el que pg_cron puede llamar.
 *
 * El refresh es global (todas las sucursales de todos los tenants); el
 * aislamiento multi-tenant ocurre en la consulta, no aqui.
 */
class RefreshConsolidatedReports extends Command
{
    protected $signature = 'reports:refresh-consolidated';

    protected $description = 'Refresca las vistas materializadas de reportes consolidados (46.6)';

    public function handle(ConsolidatedReportService $service): int
    {
        $service->refreshAll();
        $this->info('Vistas consolidadas refrescadas.');

        return self::SUCCESS;
    }
}
