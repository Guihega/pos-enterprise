<?php

declare(strict_types=1);

use App\Support\TenantTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ordenes de compra (4.1.5, maestro 26.6).
 *
 * Maquina de estados: draft -> submitted -> approved -> received.
 * cancelled desde draft, submitted o approved; NUNCA desde received.
 * El maestro fija draft como inicial (CU-COM-001) y received como terminal
 * (evento purchase_order.received) pero NO define las transiciones
 * intermedias; el resto se deduce de los endpoints de 29.7 y queda aqui
 * documentado como decision de alcance.
 *
 * `received` NO se alcanza todavia: el endpoint /receive mueve stock via
 * InventoryService y va en su propia entrega. El estado existe en el enum
 * para no migrar la columna dos veces.
 *
 * quantity_received vive en el item porque el maestro preve recepcion
 * parcial. Se persiste en 0 y lo consumira /receive.
 *
 * Sin descuentos de linea: purchase_order_items no los define en 26.6.
 * El unit_cost se captura SIEMPRE neto (tax-exclusive): el proveedor cotiza
 * antes de impuestos. Es la diferencia con sale_items, donde el precio puede
 * venir con IVA incluido segun tax.is_inclusive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            TenantTable::companyColumn($table);

            $table->unsignedBigInteger('branch_id');
            $table->foreign('branch_id')
                ->references('id')->on('branches');

            $table->unsignedBigInteger('supplier_id');
            $table->foreign('supplier_id')
                ->references('id')->on('suppliers');

            $table->string('folio', 40);
            $table->enum('status', ['draft', 'submitted', 'approved', 'received', 'cancelled'])
                ->default('draft')
                ->comment('draft->submitted->approved->received; cancelled desde cualquiera menos received');

            $table->unsignedBigInteger('requested_by')->nullable();
            $table->foreign('requested_by')
                ->references('id')->on('users');

            $table->unsignedBigInteger('approved_by')->nullable();
            $table->foreign('approved_by')
                ->references('id')->on('users');

            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->date('expected_date')->nullable();

            $table->decimal('subtotal', 18, 4)->default(0);
            $table->decimal('tax_total', 18, 4)->default(0);
            $table->decimal('total', 18, 4)->default(0);

            $table->string('notes', 500)->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['company_id', 'folio'], 'purchase_orders_company_folio_unique');
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'supplier_id']);
            $table->index(['company_id', 'branch_id']);
        });

        TenantTable::enableRls('purchase_orders');

        Schema::create('purchase_order_items', function (Blueprint $table): void {
            $table->bigIncrements('id');
            TenantTable::companyColumn($table);

            $table->unsignedBigInteger('purchase_order_id');
            $table->foreign('purchase_order_id')
                ->references('id')->on('purchase_orders')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('product_id');
            $table->foreign('product_id')
                ->references('id')->on('products');

            $table->decimal('quantity', 18, 4);
            $table->decimal('quantity_received', 18, 4)->default(0)
                ->comment('Lo consume /receive, que llega en su propia entrega');
            $table->decimal('unit_cost', 18, 4)
                ->comment('SIEMPRE neto: el proveedor cotiza antes de impuestos');
            $table->decimal('tax_rate', 7, 4)->default(0)
                ->comment('Fraccion, no porcentaje: 0.16 es 16%. Igual que taxes.rate');
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('subtotal', 18, 4);
            $table->decimal('total', 18, 4);

            $table->timestampsTz();

            $table->index(['company_id', 'purchase_order_id']);
            $table->index(['company_id', 'product_id']);
        });

        TenantTable::enableRls('purchase_order_items');
    }

    public function down(): void
    {
        TenantTable::disableRls('purchase_order_items');
        Schema::dropIfExists('purchase_order_items');
        TenantTable::disableRls('purchase_orders');
        Schema::dropIfExists('purchase_orders');
    }
};
