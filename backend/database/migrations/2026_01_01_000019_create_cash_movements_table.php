<?php

declare(strict_types=1);

use App\Support\TenantTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Movimientos de caja durante una sesión.
 *
 * Tipos:
 *   - sale_cash      : venta cobrada en efectivo (delta positivo)
 *   - sale_other     : venta cobrada en otros medios (no afecta caja física)
 *   - refund_cash    : devolución pagada en efectivo (delta negativo)
 *   - cash_in        : ingreso manual (fondo, transferencia desde bóveda)
 *   - cash_out       : retiro manual (depósito a bóveda, gasto)
 *   - tip            : propina (informativo)
 *   - adjustment     : ajuste manual con motivo
 *
 * INMUTABLE: trigger BD bloquea UPDATE/DELETE. Para corregir, anular
 * con un movimiento compensatorio.
 *
 * "amount" siempre POSITIVO; el signo se infiere del type.
 * "delta_signed" se calcula automáticamente y se almacena (denormalización
 * para sumas rápidas en cierre).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_movements', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            TenantTable::companyColumn($table);

            $table->unsignedBigInteger('cash_session_id');
            $table->foreign('cash_session_id')
                ->references('id')->on('cash_sessions')
                ->restrictOnDelete();

            $table->enum('type', [
                'sale_cash', 'sale_other', 'refund_cash',
                'cash_in', 'cash_out', 'tip', 'adjustment',
            ]);

            $table->decimal('amount', 14, 2);
            $table->decimal('delta_signed', 14, 2)
                ->comment('Cantidad con signo para cálculos rápidos. cash_out = -amount.');

            // Polimórfico: ligar a la venta, refund, etc.
            $table->string('source_type', 100)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            $table->string('reason', 500)->nullable();
            $table->string('reference', 100)->nullable();

            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')
                ->references('id')->on('users')
                ->restrictOnDelete();

            $table->jsonb('metadata')->default('{}');

            $table->timestampTz('movement_at');
            $table->timestampsTz();

            $table->index(['company_id', 'cash_session_id', 'type'], 'idx_cash_mov_session_type');
            $table->index(['source_type', 'source_id']);
            $table->index('movement_at');
        });

        TenantTable::enableRls('cash_movements');

        // Check: amount > 0 (el signo va en delta_signed)
        DB::statement('ALTER TABLE cash_movements ADD CONSTRAINT cash_movements_amount_positive
            CHECK (amount > 0)');

        // Trigger: kardex de caja inmutable
        DB::statement("
            CREATE OR REPLACE FUNCTION cash_movements_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'cash_movements is immutable: % not allowed', TG_OP;
            END;
            $$ LANGUAGE plpgsql;
        ");

        DB::statement('
            CREATE TRIGGER cash_movements_no_update
            BEFORE UPDATE ON cash_movements
            FOR EACH ROW EXECUTE FUNCTION cash_movements_immutable()
        ');

        DB::statement('
            CREATE TRIGGER cash_movements_no_delete
            BEFORE DELETE ON cash_movements
            FOR EACH ROW EXECUTE FUNCTION cash_movements_immutable()
        ');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS cash_movements_no_update ON cash_movements');
        DB::statement('DROP TRIGGER IF EXISTS cash_movements_no_delete ON cash_movements');
        DB::statement('DROP FUNCTION IF EXISTS cash_movements_immutable()');
        DB::statement('ALTER TABLE cash_movements DROP CONSTRAINT IF EXISTS cash_movements_amount_positive');
        TenantTable::disableRls('cash_movements');
        Schema::dropIfExists('cash_movements');
    }
};
