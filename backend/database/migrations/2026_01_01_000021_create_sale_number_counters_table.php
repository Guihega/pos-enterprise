<?php

declare(strict_types=1);

use App\Support\TenantTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contadores de folios.
 *
 * Cada combinación (branch_id, cash_register_id, series) tiene su propio
 * contador autoincremental. La generación de folios se hace en el servicio
 * con SELECT ... FOR UPDATE para evitar gaps por concurrencia.
 *
 * "series" típicamente es "A" para tickets normales, "B" para facturas, etc.
 * Por defecto se usa "A".
 *
 * "current_value" es el último folio generado. El siguiente será
 * current_value + 1.
 *
 * Si la fila no existe para una combinación específica, se crea con
 * current_value=0 al pedir el primer folio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_number_counters', function (Blueprint $table): void {
            $table->bigIncrements('id');
            TenantTable::companyColumn($table);

            $table->unsignedBigInteger('branch_id');
            $table->foreign('branch_id')
                ->references('id')->on('branches')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('cash_register_id');
            $table->foreign('cash_register_id')
                ->references('id')->on('cash_registers')
                ->cascadeOnDelete();

            $table->string('series', 10)->default('A');
            $table->unsignedBigInteger('current_value')->default(0);

            $table->timestampsTz();

            $table->unique(
                ['branch_id', 'cash_register_id', 'series'],
                'sale_counters_unique'
            );
            // Nota: NO agregamos index('company_id') porque TenantTable::companyColumn()
            // ya lo crea automáticamente. Repetirlo causa "duplicate index name".
        });

        TenantTable::enableRls('sale_number_counters');
    }

    public function down(): void
    {
        TenantTable::disableRls('sale_number_counters');
        Schema::dropIfExists('sale_number_counters');
    }
};
