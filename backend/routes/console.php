<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    /** @phpstan-ignore-next-line */
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// EX-043: detecta transferencias perdidas (sent sin received tras el TTL) y
// alerta al admin. Diario 06:00, alineado con las alertas de notifications
// del maestro (seccion de scheduler).
Schedule::command('transfers:detect-lost')->dailyAt('06:00');
Schedule::command('stock:check-consistency')->dailyAt('06:05');

// RN-195: lotes por caducar (30 dias default) y caducados notifican a
// almacen de la sucursal del lote. Diario 06:10, serie de alertas matutinas.
Schedule::command('batches:detect-expiring')->dailyAt('06:10');

// RN-194: dispositivos con sync caida >2h notifican a admin. Cada hora
// (no diario: una caida de 2h detectada al dia siguiente seria tardia);
// el filtro whereNull(stale_alerted_at) + limpieza en heartbeat evita spam.
Schedule::command('sync:detect-stale')->hourly();
