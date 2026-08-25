<?php

declare(strict_types=1);

use App\Support\TenantTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corridas de costeo (docs/DISENO_COSTEO.md, decisiones D1-D4 fijadas).
 *
 * Una corrida agrupa las compras de UN viaje/lote: el flete es del viaje
 * y se prorratea entre lineas por VALOR (D4). Confirmar escribe
 * products.cost; el precio queda sugerido por linea (D1=b).
 *
 * waste_pct y margin_pct son FRACCIONES (0.16 = 16%), igual que
 * taxes.rate. margin_type decide la formula por linea (D3):
 * markup: precio = costo * (1 + m); on_price: precio = costo / (1 - m).
 *
 * computed_* se persisten al calcular/confirmar para que la corrida
 * confirmada sea acta: relata lo que se decidio con los numeros de ese
 * momento, aunque el producto cambie despues.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('costing_runs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            TenantTable::companyColumn($table);

            $table->string('name', 120);
            $table->enum('status', ['draft', 'confirmed'])
                ->default('draft')
                ->comment('draft -> confirmed; confirmed es terminal');

            $table->decimal('freight_total', 18, 4)->default(0)
                ->comment('Gasolina/flete del viaje completo; se prorratea por valor');
            $table->decimal('other_costs_total', 18, 4)->default(0)
                ->comment('Casetas, viaticos, empaque... del viaje completo');
            $table->text('notes')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')
                ->references('id')->on('users');
            $table->timestampTz('confirmed_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['company_id', 'status']);
        });
        TenantTable::enableRls('costing_runs');

        Schema::create('costing_run_lines', function (Blueprint $table): void {
            $table->bigIncrements('id');
            TenantTable::companyColumn($table);

            $table->unsignedBigInteger('costing_run_id');
            $table->foreign('costing_run_id')
                ->references('id')->on('costing_runs')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('product_id');
            $table->foreign('product_id')
                ->references('id')->on('products');

            $table->string('pack_description', 60)
                ->comment('Como se compro: bulto, caja, reja...');
            $table->decimal('pack_price', 18, 4);
            $table->decimal('units_per_pack', 18, 4);
            $table->decimal('packs_qty', 18, 4);

            $table->decimal('extra_cost', 18, 4)->default(0)
                ->comment('Costos SOLO de esta linea, total (no unitario)');
            $table->decimal('waste_pct', 7, 4)->default(0)
                ->comment('Fraccion, no porcentaje: 0.05 es 5% de merma. < 1');
            $table->decimal('margin_pct', 7, 4)->default(0)
                ->comment('Fraccion. Formula segun margin_type');
            $table->enum('margin_type', ['markup', 'on_price'])
                ->default('markup')
                ->comment('markup: c*(1+m); on_price: c/(1-m), m < 1');

            $table->decimal('computed_units', 18, 4)->nullable();
            $table->decimal('computed_unit_cost', 18, 4)->nullable()
                ->comment('Landed: base + flete prorrateado + extra, ajustado por merma');
            $table->decimal('computed_price', 18, 4)->nullable()
                ->comment('Sugerido; products.price NO se toca al confirmar (D1=b)');

            $table->timestampsTz();

            $table->index(['company_id', 'costing_run_id']);
            $table->index(['company_id', 'product_id']);
        });
        TenantTable::enableRls('costing_run_lines');
    }

    public function down(): void
    {
        TenantTable::disableRls('costing_run_lines');
        Schema::dropIfExists('costing_run_lines');
        TenantTable::disableRls('costing_runs');
        Schema::dropIfExists('costing_runs');
    }
};
