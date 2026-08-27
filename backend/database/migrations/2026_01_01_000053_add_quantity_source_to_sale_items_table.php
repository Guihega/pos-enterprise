<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Venta a granel (docs/DISENO_GRANEL.md, sec. 4).
 *
 * quantity_source registra de donde salio la cantidad de una linea cuyo
 * producto se vende por peso (products.allow_decimals): 'scale' (la
 * terminal la tomo de la bascula) o 'manual' (tecleada, EX-163, exige
 * sale.weight.manual). Null en productos por pieza. Se valida en el
 * servicio, no con CHECK: la regla depende del producto, no de la fila.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table): void {
            $table->string('quantity_source', 10)->nullable()->after('quantity')
                ->comment('scale|manual; solo productos por peso (allow_decimals)');
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table): void {
            $table->dropColumn('quantity_source');
        });
    }
};
