<?php

declare(strict_types=1);

use App\Support\TenantTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Detalle de lotes consumidos por linea de venta (doc maestro 26.x
 * sale_item_batches, checkout paso 9c). El consumo es FEFO (RN-045).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_item_batches', function (Blueprint $table): void {
            $table->bigIncrements('id');
            TenantTable::companyColumn($table);

            $table->unsignedBigInteger('sale_item_id');
            $table->foreign('sale_item_id')
                ->references('id')->on('sale_items')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('batch_id');
            $table->foreign('batch_id')
                ->references('id')->on('product_batches')
                ->restrictOnDelete();

            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_cost', 14, 4);

            $table->timestampsTz();

            $table->index(['company_id', 'sale_item_id']);
            $table->index(['company_id', 'batch_id']);
        });

        TenantTable::enableRls('sale_item_batches');
    }

    public function down(): void
    {
        TenantTable::disableRls('sale_item_batches');
        Schema::dropIfExists('sale_item_batches');
    }
};
