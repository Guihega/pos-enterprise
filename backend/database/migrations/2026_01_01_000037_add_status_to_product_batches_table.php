<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Estado del lote para cuarentena (doc maestro 29.6: POST batches/
 * {uuid}/quarantine "Bloquear lote" y /release "Liberar lote").
 *
 * El maestro define los endpoints sin semantica de modelo; estandar
 * adoptado: un lote en cuarentena sale de circulacion (el consumo FEFO
 * lo excluye por completo). El indice parcial FEFO se alinea al scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_batches', function (Blueprint $table): void {
            $table->string('status', 20)->default('available')->after('cost');
        });

        DB::statement('DROP INDEX IF EXISTS idx_batches_product_branch_exp');
        DB::statement("CREATE INDEX idx_batches_product_branch_exp
            ON product_batches (product_id, branch_id, expiration_date)
            WHERE quantity > 0 AND status = 'available'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_batches_product_branch_exp');
        DB::statement('CREATE INDEX idx_batches_product_branch_exp
            ON product_batches (product_id, branch_id, expiration_date)
            WHERE quantity > 0');

        Schema::table('product_batches', function (Blueprint $table): void {
            $table->dropColumn('status');
        });
    }
};
