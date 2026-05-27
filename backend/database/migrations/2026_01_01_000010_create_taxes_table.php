<?php

declare(strict_types=1);

use App\Support\TenantTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Impuestos aplicables a ventas y productos.
 *
 * Para México:
 *   - IVA tasa 0  (productos básicos: pan, leche, frutas)
 *   - IVA 8%      (frontera)
 *   - IVA 16%     (general)
 *   - IEPS        (tabaco, alcohol, refrescos)
 *
 * Para SaaS multi-país, cada tenant gestiona sus propios impuestos.
 * Los impuestos default los siembra CatalogProvisioner según el
 * country_code del tenant.
 *
 * "rate" se almacena como porcentaje en decimal (0.16 para 16%, no 16).
 * "is_inclusive": precio del producto YA INCLUYE el impuesto (default true en MX,
 * en US típicamente false porque el tax se suma al ticket).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taxes', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            TenantTable::companyColumn($table);

            $table->string('code', 30);
            $table->string('name', 100);
            $table->string('description', 500)->nullable();

            $table->decimal('rate', 8, 6)
                ->comment('Tasa decimal: 0.16 = 16%');

            $table->enum('type', ['vat', 'sales_tax', 'excise', 'withholding', 'other'])
                ->default('vat')
                ->comment('vat=IVA, excise=IEPS, withholding=retención, etc.');

            $table->boolean('is_inclusive')->default(true)
                ->comment('Si true, el precio del producto YA INCLUYE este impuesto.');

            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false)
                ->comment('Una sola tax default por tenant; se aplica a productos sin tax explícito.');

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['company_id', 'code'], 'taxes_company_code_unique');
            $table->index(['company_id', 'is_active']);
        });

        TenantTable::enableRls('taxes');

        // Solo UN tax default por tenant (parcial unique con WHERE)
        \DB::statement('CREATE UNIQUE INDEX taxes_one_default_per_company
            ON taxes (company_id) WHERE is_default = true AND deleted_at IS NULL');
    }

    public function down(): void
    {
        \DB::statement('DROP INDEX IF EXISTS taxes_one_default_per_company');
        TenantTable::disableRls('taxes');
        Schema::dropIfExists('taxes');
    }
};
