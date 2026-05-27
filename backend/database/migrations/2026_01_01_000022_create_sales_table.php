<?php

declare(strict_types=1);

use App\Support\TenantTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ventas.
 *
 * Encabezado de la transacción. Las líneas viven en sale_items y los
 * pagos en sale_payments.
 *
 * "number" es el folio legible: BRANCH-REGISTER-SERIES-NNNNNN
 *   ej. CTR-CAJA01-A-000001
 *
 * "status":
 *   - draft     : carrito en progreso (no cuenta para reportes)
 *   - completed : venta cobrada (afecta inventario y caja)
 *   - voided    : cancelada antes de completarse (descartada, no impacta)
 *   - refunded  : devolución parcial completa (Fase 2)
 *
 * Totales:
 *   subtotal_amount    : suma de items antes de descuento
 *   discount_amount    : descuento total (puede venir de items o de la venta)
 *   tax_amount         : impuestos totales
 *   tip_amount         : propina
 *   total_amount       : final a pagar = subtotal - discount + tax + tip
 *   paid_amount        : suma de pagos (debe igualar total_amount al completar)
 *   change_amount      : cambio devuelto (efectivo)
 *
 * "currency_code": preparado para multi-divisa (Fase 2). Por ahora siempre
 * la moneda base del tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            TenantTable::companyColumn($table);

            // Folio (compuesto)
            $table->string('number', 50);
            $table->string('series', 10)->default('A');
            $table->unsignedBigInteger('number_value')
                ->comment('Solo el contador numérico (000001), por si quieren ordenar/reporte');

            // Ubicación
            $table->unsignedBigInteger('branch_id');
            $table->foreign('branch_id')
                ->references('id')->on('branches')
                ->restrictOnDelete();

            $table->unsignedBigInteger('cash_register_id');
            $table->foreign('cash_register_id')
                ->references('id')->on('cash_registers')
                ->restrictOnDelete();

            $table->unsignedBigInteger('cash_session_id');
            $table->foreign('cash_session_id')
                ->references('id')->on('cash_sessions')
                ->restrictOnDelete();

            $table->unsignedBigInteger('warehouse_id')
                ->comment('Almacén desde donde se descuenta el stock');
            $table->foreign('warehouse_id')
                ->references('id')->on('warehouses')
                ->restrictOnDelete();

            // Cliente opcional (null = público en general)
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->foreign('customer_id')
                ->references('id')->on('customers')
                ->restrictOnDelete();

            // Datos del cliente al momento de la venta (denormalizado).
            // Útil cuando el cliente se borra o se modifica después.
            $table->string('customer_name', 200)->nullable();
            $table->string('customer_tax_id', 50)->nullable();
            $table->jsonb('customer_data')->default('{}');

            // Cajero
            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')
                ->references('id')->on('users')
                ->restrictOnDelete();

            $table->enum('status', ['draft', 'completed', 'voided', 'refunded'])
                ->default('draft');

            $table->string('currency_code', 3)->default('MXN');

            // Totales
            $table->decimal('subtotal_amount', 14, 2)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('tip_amount', 14, 2)->default(0)
                ->comment('Propina (separada del total para reportes a staff)');
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->decimal('change_amount', 14, 2)->default(0);

            // Notas
            $table->string('notes', 500)->nullable();
            $table->string('void_reason', 500)->nullable();

            // Auditoría
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->foreign('voided_by')
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->jsonb('metadata')->default('{}');

            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->timestampsTz();

            // Folio único por tenant
            $table->unique(['company_id', 'number'], 'sales_company_number_unique');

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'cash_session_id']);
            $table->index(['company_id', 'customer_id']);
            $table->index(['company_id', 'completed_at']);
            $table->index(['company_id', 'branch_id', 'completed_at'], 'idx_sales_branch_date');
        });

        TenantTable::enableRls('sales');

        // Checks de no-negatividad
        DB::statement('ALTER TABLE sales ADD CONSTRAINT sales_amounts_non_negative
            CHECK (
                subtotal_amount >= 0
                AND discount_amount >= 0
                AND tax_amount >= 0
                AND tip_amount >= 0
                AND total_amount >= 0
                AND paid_amount >= 0
                AND change_amount >= 0
            )');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sales DROP CONSTRAINT IF EXISTS sales_amounts_non_negative');
        TenantTable::disableRls('sales');
        Schema::dropIfExists('sales');
    }
};
