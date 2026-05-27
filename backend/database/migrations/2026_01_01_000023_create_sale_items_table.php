<?php

declare(strict_types=1);

use App\Support\TenantTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Líneas de una venta.
 *
 * Datos del producto DENORMALIZADOS al momento de la venta:
 * sku, name, unit_name. Razón: si el producto se modifica después
 * (cambio de nombre, cambio de unidad, cambio de precio), el ticket
 * histórico debe seguir mostrando exactamente lo que se cobró.
 *
 * Cálculos por línea:
 *   line_subtotal   = quantity * unit_price
 *   line_discount   = monto descuento (puede venir de %)
 *   line_tax        = impuesto calculado
 *   line_total      = subtotal - discount + tax
 *
 * El "tax_rate" persistido es la tasa al momento de la venta (no
 * la del producto actual).
 *
 * "is_taxable" indica si la línea lleva impuesto. Si false, line_tax=0.
 *
 * "track_inventory" se copia del producto al momento de la venta para
 * decidir si descontar stock o no (servicios no descuentan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_items', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            TenantTable::companyColumn($table);

            $table->unsignedBigInteger('sale_id');
            $table->foreign('sale_id')
                ->references('id')->on('sales')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('product_id');
            $table->foreign('product_id')
                ->references('id')->on('products')
                ->restrictOnDelete();

            // Datos del producto al momento de la venta (denormalización)
            $table->string('product_sku', 60);
            $table->string('product_name', 300);
            $table->string('unit_name', 50)->nullable();

            // Cantidad y precios
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_price', 18, 4);
            $table->decimal('unit_cost', 18, 4)->default(0)
                ->comment('Costo promedio al momento (para reportes de margen)');

            // Subtotales
            $table->decimal('line_subtotal', 14, 2)
                ->comment('quantity * unit_price (antes de descuento e impuesto)');

            // Descuento (a nivel línea)
            $table->decimal('discount_percent', 5, 2)->default(0)
                ->comment('% de descuento aplicado, 0-100');
            $table->decimal('discount_amount', 14, 2)->default(0);

            // Impuesto (a nivel línea)
            $table->boolean('is_taxable')->default(true);
            $table->boolean('tax_inclusive')->default(false)
                ->comment('Si true, unit_price ya incluye el impuesto');
            $table->decimal('tax_rate', 8, 6)->default(0)
                ->comment('Tasa decimal: 0.16 = 16%');
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->string('tax_code', 30)->nullable()
                ->comment('Código del impuesto aplicado (IVA-16, etc.)');

            $table->decimal('line_total', 14, 2)
                ->comment('Total final de la línea: subtotal - discount + tax (si exclusive)');

            $table->boolean('track_inventory')->default(true);

            $table->jsonb('metadata')->default('{}');

            $table->timestampsTz();

            $table->index(['company_id', 'sale_id']);
            $table->index(['company_id', 'product_id']);
        });

        TenantTable::enableRls('sale_items');

        DB::statement('ALTER TABLE sale_items ADD CONSTRAINT sale_items_quantity_positive
            CHECK (quantity > 0)');
        DB::statement('ALTER TABLE sale_items ADD CONSTRAINT sale_items_amounts_non_negative
            CHECK (
                unit_price >= 0
                AND line_subtotal >= 0
                AND discount_amount >= 0
                AND discount_percent >= 0 AND discount_percent <= 100
                AND tax_amount >= 0
                AND line_total >= 0
            )');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sale_items DROP CONSTRAINT IF EXISTS sale_items_quantity_positive');
        DB::statement('ALTER TABLE sale_items DROP CONSTRAINT IF EXISTS sale_items_amounts_non_negative');
        TenantTable::disableRls('sale_items');
        Schema::dropIfExists('sale_items');
    }
};
