<?php

declare(strict_types=1);

use App\Support\TenantTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cajas (puntos de cobro) físicas.
 *
 * Una sucursal puede tener N cajas. Cada caja se identifica con un
 * código corto (CAJA-01, CAJA-02). Una caja tiene cero o una sesión
 * abierta a la vez (un turno).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_registers', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            TenantTable::companyColumn($table);

            $table->unsignedBigInteger('branch_id');
            $table->foreign('branch_id')
                ->references('id')->on('branches')
                ->cascadeOnDelete();

            $table->string('code', 30);
            $table->string('name', 200);
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['company_id', 'code'], 'cash_registers_company_code_unique');
            $table->index(['company_id', 'branch_id']);
        });

        TenantTable::enableRls('cash_registers');
    }

    public function down(): void
    {
        TenantTable::disableRls('cash_registers');
        Schema::dropIfExists('cash_registers');
    }
};
