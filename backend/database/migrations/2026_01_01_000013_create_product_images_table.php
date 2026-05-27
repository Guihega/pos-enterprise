<?php

declare(strict_types=1);

use App\Support\TenantTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Imágenes de producto.
 *
 * Almacenamos URL al objeto en S3 / cloud storage. La estrategia de
 * subida (presigned URLs) se cubre en bloque posterior; aquí solo
 * persistimos las referencias.
 *
 * Una imagen es "primary" — la principal mostrada en listados.
 * El resto se muestran en galería al ver detalle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_images', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            TenantTable::companyColumn($table);

            $table->unsignedBigInteger('product_id');
            $table->foreign('product_id')
                ->references('id')->on('products')
                ->cascadeOnDelete();

            $table->string('url', 1000);
            $table->string('thumbnail_url', 1000)->nullable();
            $table->string('alt_text', 200)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedInteger('size_bytes')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_primary')->default(false);

            $table->timestampsTz();

            $table->index(['company_id', 'product_id', 'sort_order']);
        });

        TenantTable::enableRls('product_images');

        // Solo UNA imagen primary por producto (parcial unique)
        DB::statement('CREATE UNIQUE INDEX product_images_one_primary_per_product
            ON product_images (product_id) WHERE is_primary = true');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS product_images_one_primary_per_product');
        TenantTable::disableRls('product_images');
        Schema::dropIfExists('product_images');
    }
};
