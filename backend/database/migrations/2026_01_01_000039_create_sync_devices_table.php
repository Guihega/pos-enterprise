<?php

declare(strict_types=1);

use App\Support\TenantTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Doc maestro 26.12: sync_devices. Dispositivo cliente (pos, mobile,
     * kiosk) registrado por sucursal via POST /api/v1/sync/registration
     * (29.x). Persistir last_seen_at desbloquea RN-194 (sync caida >2h
     * notifica a admin), deuda de Fase 3: el heartbeat actual es stateless.
     *
     * Estandares adoptados y documentados:
     * - folio_range_start/end/next se incluyen como columnas (DDL completo
     *   del maestro, evita migracion futura) pero la LOGICA de folios por
     *   dispositivo se DIFIERE hasta integrar ADR-0009.
     * - stale_alerted_at no esta en el DDL: es la marca de idempotencia
     *   RN-194 patron EX-042 (la caida revierte: se limpia cuando el
     *   dispositivo vuelve a reportar heartbeat).
     * - auth/devices/* (29.x seccion auth) es dominio de autorizacion de
     *   dispositivos, distinto de sync_devices; DIFERIDO.
     */
    public function up(): void
    {
        Schema::create('sync_devices', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            TenantTable::companyColumn($table);

            $table->unsignedBigInteger('branch_id');
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();

            $table->unsignedBigInteger('user_id')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();

            $table->string('device_id', 100);
            $table->string('name', 120)->nullable();
            $table->string('type', 20); // pos, mobile, kiosk (26.12)
            $table->string('fingerprint', 255)->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('last_sync_at')->nullable();
            $table->timestampTz('stale_alerted_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('folio_range_start')->nullable();
            $table->integer('folio_range_end')->nullable();
            $table->integer('folio_next')->nullable();
            $table->jsonb('settings')->default('{}');
            $table->timestampsTz();

            $table->unique(['company_id', 'device_id']);
            $table->index(['company_id', 'branch_id']);
        });

        TenantTable::enableRls('sync_devices');
    }

    public function down(): void
    {
        TenantTable::disableRls('sync_devices');
        Schema::dropIfExists('sync_devices');
    }
};
