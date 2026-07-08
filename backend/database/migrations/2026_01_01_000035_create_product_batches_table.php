<?php

declare(strict_types=1);

use App\Support\TenantTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lotes de producto (doc maestro 26.x product_batches).
 *
 * Subdivision de un producto por origen, fecha de recepcion y caducidad.
 * expiration_date nullable: RN-046 permite lote sin caducidad (flag).
 * received_quantity conserva lo recibido; quantity es el remanente vivo
 * (el indice parcial FEFO solo considera quantity > 0, RN-045).
 *
 * NOTA: el schema del maestro incluye supplier_id y purchase_order_id;
 * se omiten porque el dominio Purchasing (suppliers, purchase_orders)
 * no existe aun. Se agregaran con FK real cuando se construya ese epic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_batches', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            TenantTable::companyColumn($table);

            $table->unsignedBigInteger('product_id');
            $table->foreign('product_id')
                ->references('id')->on('products')
                ->restrictOnDelete();

            $table->unsignedBigInteger('branch_id');
            $table->foreign('branch_id')
                ->references('id')->on('branches')
                ->restrictOnDelete();

            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->foreign('warehouse_id')
                ->references('id')->on('warehouses')
                ->nullOnDelete();

            $table->string('lot_number', 60)->nullable();
            $table->date('expiration_date')->nullable();
            $table->date('received_date');
            $table->decimal('received_quantity', 14, 3);
            $table->decimal('quantity', 14, 3);
            $table->decimal('cost', 14, 4);

            $table->text('notes')->nullable();

            $table->timestampsTz();

            $table->index(['company_id', 'product_id']);
            $table->index(['company_id', 'branch_id']);
        });

        TenantTable::enableRls('product_batches');

        // Indice parcial FEFO (RN-045): solo lotes con remanente.
        DB::statement('CREATE INDEX idx_batches_product_branch_exp
            ON product_batches (product_id, branch_id, expiration_date)
            WHERE quantity > 0');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_batches_product_branch_exp');
        TenantTable::disableRls('product_batches');
        Schema::dropIfExists('product_batches');
    }
};
