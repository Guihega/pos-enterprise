<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [deuda-19] Proveedor en el movimiento de inventario.
 *
 * La captura de proveedor en ajustes via product_batches solo cubre
 * productos con tracks_lots=true. La compra informal (perecederos,
 * compra sin OC) tipicamente no maneja lotes, que es justo el caso
 * que motivo la deuda. El movimiento de entrada es el hecho contable
 * que si ocurre siempre, asi que el proveedor se registra aqui.
 *
 * Nullable: la mayoria de movimientos (ventas, traspasos, mermas)
 * no tienen proveedor. nullOnDelete: el kardex es inmutable y debe
 * sobrevivir al borrado logico del proveedor.
 *
 * No reemplaza product_batches.supplier_id (000049): el lote sigue
 * siendo la fuente para FEFO/caducidad; esta columna es la fuente
 * para trazabilidad de compra sin lote.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->unsignedBigInteger('supplier_id')->nullable()->after('user_id');
            $table->foreign('supplier_id')
                ->references('id')->on('suppliers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->dropForeign(['supplier_id']);
            $table->dropColumn('supplier_id');
        });
    }
};
