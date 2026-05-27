<?php

declare(strict_types=1);

use App\Support\TenantTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Desglose de impuestos por venta.
 *
 * Cada fila resume cuánto se cobró de un impuesto (por código) en una venta.
 * Permite reportes fiscales del tipo "total IVA-16 cobrado este mes" sin
 * tener que iterar sale_items.
 *
 * Si una venta tiene 5 items con IVA 16% y 2 items con IVA 8%, habrá
 * 2 filas en sale_taxes para esa venta:
 *   (sale_id=42, code='IVA-16', taxable_base=850, amount=136)
 *   (sale_id=42, code='IVA-8',  taxable_base=200, amount=16)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_taxes', function (Blueprint $table): void {
            $table->bigIncrements('id');
            TenantTable::companyColumn($table);

            $table->unsignedBigInteger('sale_id');
            $table->foreign('sale_id')
                ->references('id')->on('sales')
                ->cascadeOnDelete();

            $table->string('code', 30)
                ->comment('Código del impuesto: IVA-16, IVA-8, etc.');
            $table->string('name', 100);
            $table->decimal('rate', 8, 6)
                ->comment('Tasa decimal del impuesto');
            $table->decimal('taxable_base', 14, 2)
                ->comment('Base gravable: sum(line_subtotal - discount) de items con este impuesto');
            $table->decimal('amount', 14, 2)
                ->comment('Monto del impuesto: sum(line_tax) de items con este impuesto');

            $table->timestampsTz();

            $table->unique(['sale_id', 'code'], 'sale_taxes_sale_code_unique');
            $table->index(['company_id', 'code']);
        });

        TenantTable::enableRls('sale_taxes');

        DB::statement('ALTER TABLE sale_taxes ADD CONSTRAINT sale_taxes_amounts_non_negative
            CHECK (taxable_base >= 0 AND amount >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sale_taxes DROP CONSTRAINT IF EXISTS sale_taxes_amounts_non_negative');
        TenantTable::disableRls('sale_taxes');
        Schema::dropIfExists('sale_taxes');
    }
};
