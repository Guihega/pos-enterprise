<?php

declare(strict_types=1);

use App\Support\TenantTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lineas de una solicitud de transferencia (CU-GER-003).
 * Cantidades SOLICITADAS; las enviadas/recibidas viven en transfer_items
 * del Transfer que se crea al aprobar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfer_request_items', function (Blueprint $table): void {
            $table->bigIncrements('id');
            TenantTable::companyColumn($table);

            $table->unsignedBigInteger('transfer_request_id');
            $table->foreign('transfer_request_id')
                ->references('id')->on('transfer_requests')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('product_id');
            $table->foreign('product_id')
                ->references('id')->on('products')
                ->restrictOnDelete();

            $table->decimal('quantity', 18, 4);
            $table->text('notes')->nullable();

            $table->timestampsTz();

            $table->index(['company_id', 'transfer_request_id'], 'idx_tr_req_items_request');
            $table->index(['company_id', 'product_id'], 'idx_tr_req_items_product');
        });

        TenantTable::enableRls('transfer_request_items');
    }

    public function down(): void
    {
        TenantTable::disableRls('transfer_request_items');
        Schema::dropIfExists('transfer_request_items');
    }
};
