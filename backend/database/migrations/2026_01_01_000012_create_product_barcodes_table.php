<?php

declare(strict_types=1);

use App\Support\TenantTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Códigos de barras de productos.
 *
 * Un producto puede tener varios:
 *   - El código del fabricante (EAN-13)
 *   - El código interno de inventario
 *   - El código de la presentación pequeña vs grande (mismo SKU, distinto barcode)
 *
 * Único POR TENANT. Sí, en teoría un EAN debería ser globalmente único, pero
 * en la práctica:
 *   - Hay tiendas que reusan códigos para productos a granel
 *   - Hay códigos internos que no son EAN
 *   - Permite a cada cliente mantener su propio universo
 *
 * "type" identifica el formato (UPC-A, EAN-13, EAN-8, CODE-128, custom).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_barcodes', function (Blueprint $table): void {
            $table->bigIncrements('id');
            TenantTable::companyColumn($table);

            $table->unsignedBigInteger('product_id');
            $table->foreign('product_id')
                ->references('id')->on('products')
                ->cascadeOnDelete();

            $table->string('barcode', 60);
            $table->enum('type', ['ean-13', 'ean-8', 'upc-a', 'upc-e', 'code-128', 'code-39', 'qr', 'custom'])
                ->default('ean-13');
            $table->boolean('is_primary')->default(false)
                ->comment('Código primario que se imprime en etiquetas');
            $table->decimal('pack_quantity', 12, 4)->default(1)
                ->comment('Cuántas unidades del producto representa este barcode (e.g. caja de 6)');

            $table->timestampsTz();

            $table->unique(['company_id', 'barcode'], 'product_barcodes_company_barcode_unique');
            $table->index(['company_id', 'product_id']);
        });

        TenantTable::enableRls('product_barcodes');
    }

    public function down(): void
    {
        TenantTable::disableRls('product_barcodes');
        Schema::dropIfExists('product_barcodes');
    }
};
