<?php

declare(strict_types=1);

use App\Support\TenantTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unidades de medida y venta.
 *
 * Categorías:
 *   - count    : pieza, par, docena (1, 2, 12)
 *   - weight   : gramo, kilo, libra
 *   - volume   : ml, litro, galón
 *   - length   : cm, metro, pulgada
 *   - other    : caja, paquete, bolsa
 *
 * "factor" indica el factor de conversión hacia la unidad base de
 * la categoría (1 kg = 1000 g; factor del kg = 1000, factor del g = 1).
 * Esto permite cálculos automáticos cuando un producto se vende en
 * unidades distintas a las que se compra.
 *
 * "is_decimal" determina si admite cantidades fraccionarias
 * (peso/volumen sí; pieza no).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            TenantTable::companyColumn($table);

            $table->string('code', 20)
                ->comment('PZA, KG, LT, MT, etc. Único por tenant.');
            $table->string('name', 100);
            $table->string('plural_name', 100)->nullable();
            $table->string('symbol', 10)->nullable()
                ->comment('Pza, kg, l, m, etc.');

            $table->enum('category', ['count', 'weight', 'volume', 'length', 'other'])
                ->default('count');

            $table->decimal('factor', 18, 6)->default(1)
                ->comment('Factor hacia unidad base de la categoría.');

            $table->boolean('is_decimal')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['company_id', 'code'], 'units_company_code_unique');
            $table->index(['company_id', 'category']);
        });

        TenantTable::enableRls('units');
    }

    public function down(): void
    {
        TenantTable::disableRls('units');
        Schema::dropIfExists('units');
    }
};
