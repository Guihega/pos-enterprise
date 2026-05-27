<?php

declare(strict_types=1);

use App\Support\TenantTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sesiones de caja (turnos).
 *
 * Una sesión es el periodo entre la apertura de la caja y su cierre.
 * Mientras está abierta:
 *   - Recibe ventas (las ventas del bloque 1.7 referenciarán cash_session_id)
 *   - Permite movimientos manuales (ingresos extra, retiros)
 *
 * Al cerrar:
 *   - Se calcula expected_amount = opening + movimientos del turno
 *   - El cajero cuenta físicamente y declara counted_amount
 *   - difference = counted - expected
 *
 * Status:
 *   - open      : turno activo, recibiendo ventas/movimientos
 *   - closed    : cerrada normalmente
 *   - voided    : anulada por supervisor (no contabiliza)
 *
 * Constraint: solo UNA sesión open por register (parcial unique).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_sessions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            TenantTable::companyColumn($table);

            $table->unsignedBigInteger('cash_register_id');
            $table->foreign('cash_register_id')
                ->references('id')->on('cash_registers')
                ->restrictOnDelete();

            $table->unsignedBigInteger('branch_id');
            $table->foreign('branch_id')
                ->references('id')->on('branches')
                ->restrictOnDelete();

            // Cajero que abrió
            $table->unsignedBigInteger('opened_by');
            $table->foreign('opened_by')
                ->references('id')->on('users')
                ->restrictOnDelete();

            // Quien cerró (puede ser otro usuario, ej. supervisor)
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->foreign('closed_by')
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->enum('status', ['open', 'closed', 'voided'])->default('open');

            // Montos en moneda base (decimal 14,2 estándar contable)
            $table->decimal('opening_amount', 14, 2)->default(0)
                ->comment('Efectivo en caja al abrir el turno');
            $table->decimal('expected_amount', 14, 2)->nullable()
                ->comment('Calculado por sistema al cerrar: opening + entradas - salidas');
            $table->decimal('counted_amount', 14, 2)->nullable()
                ->comment('Efectivo físicamente contado por el cajero al cerrar');
            $table->decimal('difference', 14, 2)->nullable()
                ->comment('counted - expected. Negativo = faltante, positivo = sobrante');

            // Notas
            $table->string('opening_notes', 500)->nullable();
            $table->string('closing_notes', 500)->nullable();

            $table->timestampTz('opened_at');
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();

            $table->index(['company_id', 'cash_register_id', 'status'], 'idx_cash_sessions_status');
            $table->index(['company_id', 'opened_by']);
            $table->index('opened_at');
        });

        TenantTable::enableRls('cash_sessions');

        // Solo UNA sesión 'open' por cash_register (parcial unique)
        DB::statement("CREATE UNIQUE INDEX cash_sessions_one_open_per_register
            ON cash_sessions (cash_register_id) WHERE status = 'open'");

        // Check: opening_amount no negativo
        DB::statement('ALTER TABLE cash_sessions ADD CONSTRAINT cash_sessions_amounts_non_negative
            CHECK (opening_amount >= 0
                   AND (expected_amount IS NULL OR expected_amount >= 0)
                   AND (counted_amount IS NULL OR counted_amount >= 0))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE cash_sessions DROP CONSTRAINT IF EXISTS cash_sessions_amounts_non_negative');
        DB::statement('DROP INDEX IF EXISTS cash_sessions_one_open_per_register');
        TenantTable::disableRls('cash_sessions');
        Schema::dropIfExists('cash_sessions');
    }
};
