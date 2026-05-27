<?php

declare(strict_types=1);

use App\Support\TenantTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pagos de una venta (multi-payment).
 *
 * Una venta puede pagarse con múltiples métodos: ej. efectivo + tarjeta.
 *
 * Métodos soportados:
 *   - cash         : efectivo (afecta caja física)
 *   - card_credit  : tarjeta de crédito
 *   - card_debit   : tarjeta de débito
 *   - transfer     : transferencia bancaria
 *   - check        : cheque
 *   - voucher      : vale/cupón
 *   - credit       : crédito al cliente (incrementa customer.credit_balance)
 *   - other        : otros (mixto, fidelización, etc.)
 *
 * Para efectivo:
 *   amount   = monto que paga
 *   tendered = lo que entrega físicamente (puede ser mayor)
 *   change   = vuelto = tendered - amount (solo informativo aquí; el cambio
 *              se registra a nivel sale)
 *
 * "reference": número de transacción del banco, tarjeta, vale, etc.
 *
 * "captured_at": cuándo se capturó (puede diferir del created_at en pagos
 * pre-autorizados).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_payments', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            TenantTable::companyColumn($table);

            $table->unsignedBigInteger('sale_id');
            $table->foreign('sale_id')
                ->references('id')->on('sales')
                ->cascadeOnDelete();

            $table->enum('method', [
                'cash', 'card_credit', 'card_debit',
                'transfer', 'check', 'voucher', 'credit', 'other',
            ]);

            $table->decimal('amount', 14, 2)
                ->comment('Monto efectivamente abonado a la venta');
            $table->decimal('tendered_amount', 14, 2)->nullable()
                ->comment('Solo para cash: lo que entregó el cliente');

            $table->string('reference', 100)->nullable()
                ->comment('Folio bancario, número tarjeta últimos 4, vale, etc.');
            $table->string('authorization_code', 50)->nullable();
            $table->string('card_brand', 30)->nullable();
            $table->string('card_last4', 4)->nullable();

            $table->jsonb('metadata')->default('{}');

            $table->timestampTz('captured_at');
            $table->timestampsTz();

            $table->index(['company_id', 'sale_id']);
            $table->index(['company_id', 'method']);
            $table->index('captured_at');
        });

        TenantTable::enableRls('sale_payments');

        DB::statement('ALTER TABLE sale_payments ADD CONSTRAINT sale_payments_amount_positive
            CHECK (amount > 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sale_payments DROP CONSTRAINT IF EXISTS sale_payments_amount_positive');
        TenantTable::disableRls('sale_payments');
        Schema::dropIfExists('sale_payments');
    }
};
