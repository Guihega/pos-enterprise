<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trazabilidad del lote hacia Purchasing.
 *
 * La 000035 dejo escrito que omitia supplier_id y purchase_order_id
 * porque el dominio Purchasing no existia aun y que se agregarian con
 * FK real al construir ese epic. Ese epic ya existe (000046 suppliers,
 * 000047 purchase_orders), asi que se recuperan aqui.
 *
 * Ambas nullable: los lotes creados por ajuste de inventario, devolucion
 * de cliente o traspaso no tienen orden de compra. nullOnDelete y no
 * restrictOnDelete porque el histórico del lote sobrevive al borrado
 * logico de su origen.
 *
 * No duplica la relacion polimorfica de inventory_movements.source: ese
 * apunta al movimiento, este permite conocer el origen del lote sin
 * recorrer movimientos (FEFO y caducidad consultan el lote directo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_batches', function (Blueprint $table): void {
            $table->unsignedBigInteger('supplier_id')->nullable()->after('warehouse_id');
            $table->foreign('supplier_id')
                ->references('id')->on('suppliers')
                ->nullOnDelete();

            $table->unsignedBigInteger('purchase_order_id')->nullable()->after('supplier_id');
            $table->foreign('purchase_order_id')
                ->references('id')->on('purchase_orders')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_batches', function (Blueprint $table): void {
            $table->dropForeign(['supplier_id']);
            $table->dropForeign(['purchase_order_id']);
            $table->dropColumn(['supplier_id', 'purchase_order_id']);
        });
    }
};
