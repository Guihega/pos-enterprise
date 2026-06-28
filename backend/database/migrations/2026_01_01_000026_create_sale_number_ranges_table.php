<?php

declare(strict_types=1);
use App\Support\TenantTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rangos de folios reservados por dispositivo.
 *
 * ADR-0009: el servidor asigna rangos disjuntos por (cash_register, series, device_id).
 * El cliente PWA consume nextValue localmente sin red. sale_number_counters sigue siendo
 * el techo global del que se reparten los rangos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_number_ranges', function (Blueprint $table): void {
            $table->bigIncrements('id');
            TenantTable::companyColumn($table);
            $table->unsignedBigInteger('cash_register_id');
            $table->foreign('cash_register_id')
                ->references('id')->on('cash_registers')
                ->cascadeOnDelete();
            $table->string('series', 10)->default('A');
            $table->string('device_id', 36);
            $table->unsignedInteger('range_start');
            $table->unsignedInteger('range_end');
            $table->timestampTz('exhausted_at')->nullable();
            $table->timestampsTz();

            $table->index(['cash_register_id', 'series', 'device_id', 'exhausted_at'],
                'snr_active_idx');
        });

        TenantTable::enableRls('sale_number_ranges');
    }

    public function down(): void
    {
        TenantTable::disableRls('sale_number_ranges');
        Schema::dropIfExists('sale_number_ranges');
    }
};
