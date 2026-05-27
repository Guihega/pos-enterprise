<?php

declare(strict_types=1);

use App\Support\TenantTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Categorías del catálogo.
 *
 * Estructura jerárquica auto-referencial: una categoría puede tener
 * categoría padre. Útil para:
 *   - Bebidas → Refrescos → Cola
 *   - Abarrotes → Galletas → Saladas
 *
 * Profundidad máxima recomendada: 4 niveles. No la imponemos en BD;
 * el frontend la valida (UX) y un job verifica integridad nightly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            TenantTable::companyColumn($table);

            $table->unsignedBigInteger('parent_id')->nullable();
            $table->foreign('parent_id')
                ->references('id')->on('categories')
                ->nullOnDelete();

            $table->string('name', 200);
            $table->string('slug', 200);
            $table->string('description', 500)->nullable();
            $table->string('icon', 50)->nullable()
                ->comment('Nombre del icono lucide-react para UI');
            $table->string('color', 7)->nullable()
                ->comment('Color hex para UI');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['company_id', 'slug'], 'categories_company_slug_unique');
            $table->index(['company_id', 'parent_id']);
            $table->index(['company_id', 'is_active']);
        });

        TenantTable::enableRls('categories');
    }

    public function down(): void
    {
        TenantTable::disableRls('categories');
        Schema::dropIfExists('categories');
    }
};
