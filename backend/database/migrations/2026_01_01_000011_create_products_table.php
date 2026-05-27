<?php

declare(strict_types=1);

use App\Support\TenantTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla central del catálogo: products.
 *
 * NOTA HISTÓRICA (Bloque 1.4b reconciliado):
 *   En el bootstrap inicial existió una versión previa con nombres distintos
 *   (cost_price, selling_price, is_active, attributes, search_vector tsvector).
 *   Esta versión es la definitiva y reemplaza completamente a la anterior.
 *
 * Decisiones de schema:
 *   - SKU único por tenant (no global): dos clientes pueden tener el mismo SKU.
 *   - Precios en decimal(18,4) — suficiente para precios + redondeos sin drift.
 *   - parent_id self-ref nullable: preparado para variants en Fase 2 (color/talla).
 *     En MVP siempre NULL.
 *   - track_inventory: si false, no se descuenta stock al vender (servicios).
 *   - is_sellable: false para insumos internos no exhibidos al cajero.
 *   - status: lifecycle del producto (draft → active → archived).
 *     Reemplaza el doble flag is_active+is_sellable que era ambiguo.
 *   - GIN trigram en name para búsqueda LIKE/ILIKE eficiente.
 *   - custom_attributes: NO se llama "attributes" porque ese nombre choca
 *     con la propiedad mágica de Eloquent ($model->attributes devuelve el
 *     array de columnas, no el JSON).
 *
 * FKs:
 *   - category_id, brand_id, tax_id: nullable.
 *   - unit_id: NOT NULL con restrictOnDelete (no borrar unidad en uso).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            TenantTable::companyColumn($table);

            // FKs catálogo auxiliar
            $table->unsignedBigInteger('category_id')->nullable();
            $table->foreign('category_id')
                ->references('id')->on('categories')
                ->nullOnDelete();

            $table->unsignedBigInteger('brand_id')->nullable();
            $table->foreign('brand_id')
                ->references('id')->on('brands')
                ->nullOnDelete();

            $table->unsignedBigInteger('unit_id');
            $table->foreign('unit_id')
                ->references('id')->on('units')
                ->restrictOnDelete()
                ->comment('No se puede borrar una unidad si hay productos que la usan');

            $table->unsignedBigInteger('tax_id')->nullable();
            $table->foreign('tax_id')
                ->references('id')->on('taxes')
                ->nullOnDelete();

            // Variants (Fase 2): parent_id self-ref
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->foreign('parent_id')
                ->references('id')->on('products')
                ->cascadeOnDelete();

            // Identificación comercial
            $table->string('sku', 60);
            $table->string('name', 300);
            $table->string('description', 2000)->nullable();
            $table->string('short_description', 500)->nullable();

            // Precios (decimal precision = 18, scale = 4)
            $table->decimal('cost', 18, 4)->default(0)
                ->comment('Costo de adquisición (para reportes de margen)');
            $table->decimal('price', 18, 4)
                ->comment('Precio de venta al público');
            $table->decimal('compare_at_price', 18, 4)->nullable()
                ->comment('Precio "antes": para tachar y mostrar descuento');
            $table->decimal('min_price', 18, 4)->nullable()
                ->comment('Precio mínimo permitido (para descuentos manuales)');

            // Flags de comportamiento
            $table->boolean('track_inventory')->default(true);
            $table->boolean('is_sellable')->default(true)
                ->comment('Si false, no aparece en la pantalla de venta');
            $table->boolean('is_purchasable')->default(true)
                ->comment('Si false, no aparece en órdenes de compra');
            $table->boolean('allow_decimals')->default(false)
                ->comment('Si true, vendible en cantidades fraccionarias');

            // Estado
            $table->enum('status', ['draft', 'active', 'archived'])->default('active');
            $table->timestamp('published_at')->nullable();

            // Atributos físicos / fiscales
            $table->decimal('weight', 12, 4)->nullable()
                ->comment('Peso en gramos (para envíos)');
            $table->string('weight_unit', 5)->default('g');
            $table->jsonb('dimensions')->nullable()
                ->comment('{"length":..., "width":..., "height":..., "unit":"cm"}');
            $table->string('tax_code', 30)->nullable()
                ->comment('Código fiscal externo (clave SAT en MX, etc.)');

            // Metadata extensible.
            // OJO: nombre "custom_attributes" intencional. NO renombrar a "attributes"
            // sin un plan de aliasing — choca con Eloquent::$attributes.
            $table->jsonb('custom_attributes')->default('{}');
            $table->jsonb('metadata')->default('{}');

            $table->timestampsTz();
            $table->softDeletesTz();

            // Constraints
            $table->unique(['company_id', 'sku'], 'products_company_sku_unique');
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'is_sellable', 'status']);
            $table->index(['company_id', 'category_id']);
            $table->index(['company_id', 'brand_id']);
        });

        TenantTable::enableRls('products');

        // GIN trigram para búsqueda LIKE eficiente sobre name
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE INDEX products_name_trgm_idx ON products USING gin (name gin_trgm_ops)');

        // Check: precios no negativos
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_price_non_negative
            CHECK (price >= 0 AND cost >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_price_non_negative');
        DB::statement('DROP INDEX IF EXISTS products_name_trgm_idx');
        TenantTable::disableRls('products');
        Schema::dropIfExists('products');
    }
};
