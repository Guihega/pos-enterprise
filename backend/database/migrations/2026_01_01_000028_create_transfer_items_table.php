<?php

declare(strict_types=1);

use App\Support\TenantTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lineas de una transferencia inter-sucursal (doc maestro 46.4).
 *
 * quantity_sent: cantidad despachada al pasar a 'sent' (descuenta origen).
 * quantity_received: cantidad confirmada al recibir; NULL hasta la recepcion.
 * Si quantity_received < quantity_sent => merma => ajuste transfer_loss (RN-049).
 *
 * unit_cost: costo unitario al momento del envio (kardex valorizado).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfer_items', function (Blueprint $table): void {
            $table->bigIncrements('id');
            TenantTable::companyColumn($table);

            $table->unsignedBigInteger('transfer_id');
            $table->foreign('transfer_id')
                ->references('id')->on('transfers')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('product_id');
            $table->foreign('product_id')
                ->references('id')->on('products')
                ->restrictOnDelete();

            $table->decimal('quantity_sent', 18, 4);
            $table->decimal('quantity_received', 18, 4)->nullable();
            $table->decimal('unit_cost', 18, 4)->default(0);

            $table->text('notes')->nullable();

            $table->timestampsTz();

            $table->index(['company_id', 'transfer_id']);
            $table->index(['company_id', 'product_id']);
        });

        TenantTable::enableRls('transfer_items');

        DB::statement('ALTER TABLE transfer_items ADD CONSTRAINT transfer_items_quantity_sent_positive
            CHECK (quantity_sent > 0)');
        DB::statement('ALTER TABLE transfer_items ADD CONSTRAINT transfer_items_quantity_received_non_negative
            CHECK (quantity_received IS NULL OR quantity_received >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE transfer_items DROP CONSTRAINT IF EXISTS transfer_items_quantity_sent_positive');
        DB::statement('ALTER TABLE transfer_items DROP CONSTRAINT IF EXISTS transfer_items_quantity_received_non_negative');
        TenantTable::disableRls('transfer_items');
        Schema::dropIfExists('transfer_items');
    }
};
