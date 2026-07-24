<?php

declare(strict_types=1);

use App\Support\TenantTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Devoluciones basicas (CU-CAJ-010, RN-085/086): SaleReturn con
     * items. Reverso parcial o total de una venta completada; el
     * control de sobre-devolucion se calcula sumando returns previos
     * por item (sales no lleva acumulado, decision documentada).
     *
     * Alcance diferido (PR de devoluciones basicas): RN-087 sin
     * ticket, RN-088 cambios atomicos, CreditNote/CFDI (sin modulo de
     * facturacion), vale como reembolso, devolucion via sync.
     *
     * sale_return_items sin company_id ni RLS propio: acceso via
     * cabecera (patron sync_operations, la cabecera es la frontera
     * tenant).
     */
    public function up(): void
    {
        Schema::create('sale_returns', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            TenantTable::companyColumn($table);
            $table->unsignedBigInteger('sale_id');
            $table->foreign('sale_id')->references('id')->on('sales')->restrictOnDelete();
            $table->unsignedBigInteger('branch_id');
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->unsignedBigInteger('cash_session_id');
            $table->foreign('cash_session_id')->references('id')->on('cash_sessions')->restrictOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->decimal('total_amount', 12, 4);
            $table->decimal('cash_refunded', 12, 4)->default(0);
            $table->string('reason', 500);
            $table->timestampTz('created_at')->useCurrent();
        });
        TenantTable::enableRls('sale_returns');

        Schema::create('sale_return_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('sale_return_id');
            $table->foreign('sale_return_id')->references('id')->on('sale_returns')->cascadeOnDelete();
            $table->unsignedBigInteger('sale_item_id');
            $table->foreign('sale_item_id')->references('id')->on('sale_items')->restrictOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
            $table->decimal('quantity', 12, 4);
            $table->decimal('amount', 12, 4);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_return_items');
        Schema::dropIfExists('sale_returns');
    }
};
