<?php

declare(strict_types=1);

use App\Support\TenantTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marcas comerciales asociadas a productos.
 *
 * No es jerárquica. Cada marca pertenece a un tenant. Slug único
 * por tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            TenantTable::companyColumn($table);

            $table->string('name', 200);
            $table->string('slug', 200);
            $table->string('description', 500)->nullable();
            $table->string('logo_url', 500)->nullable();
            $table->string('website', 200)->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['company_id', 'slug'], 'brands_company_slug_unique');
            $table->index(['company_id', 'is_active']);
        });

        TenantTable::enableRls('brands');
    }

    public function down(): void
    {
        TenantTable::disableRls('brands');
        Schema::dropIfExists('brands');
    }
};
