<?php

declare(strict_types=1);

use App\Support\TenantTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stock por producto y almacén.
 *
 * Cada combinación (product_id, warehouse_id) tiene UN registro.
 * Las operaciones de inventario (entradas, salidas, transferencias)
 * actualizan este registro mediante operaciones atómicas en BD.
 *
 * "quantity_on_hand": cantidad físicamente presente
 * "quantity_reserved": cantidad apartada para órdenes en proceso
 *                      (carrito abierto, factura pendiente de pago, etc.)
 * "quantity_available": derivado, quantity_on_hand - quantity_reserved
 *
 * "stock_min" / "stock_max": umbrales de alerta. Si los productos los
 * definen a nivel producto, se usan ahí. A nivel stock se sobrescribe
 * para casos específicos por sucursal/almacén.
 *
 * "average_cost": costo promedio ponderado del stock actual. Se
 * recalcula con cada entrada (compra/devolución de cliente).
 * Es columna porque calcularlo on-the-fly es caro.
 *
 * Locking: las operaciones de stock usan SELECT ... FOR UPDATE
 * (lockForUpdate de Eloquent) para prevenir race conditions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stocks', function (Blueprint $table): void {
            $table->bigIncrements('id');
            TenantTable::companyColumn($table);

            $table->unsignedBigInteger('product_id');
            $table->foreign('product_id')
                ->references('id')->on('products')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('warehouse_id');
            $table->foreign('warehouse_id')
                ->references('id')->on('warehouses')
                ->cascadeOnDelete();

            // Cantidades - decimal(18,4) para soportar fracciones
            $table->decimal('quantity_on_hand', 18, 4)->default(0);
            $table->decimal('quantity_reserved', 18, 4)->default(0);

            // Umbrales por almacén (override del producto)
            $table->decimal('stock_min', 18, 4)->nullable();
            $table->decimal('stock_max', 18, 4)->nullable();

            // Costo promedio ponderado
            $table->decimal('average_cost', 18, 4)->default(0);

            // Última fecha de movimiento (para reportes "stock muerto")
            $table->timestamp('last_movement_at')->nullable();

            $table->timestampsTz();

            $table->unique(['product_id', 'warehouse_id'], 'stocks_product_warehouse_unique');
            $table->index(['company_id', 'warehouse_id']);
            $table->index(['company_id', 'product_id']);
        });

        TenantTable::enableRls('stocks');

        // Check: cantidades no negativas. quantity_reserved <= quantity_on_hand
        // queda como invariante de servicio (no de BD) porque hay momentos
        // intermedios durante operaciones donde puede invertirse temporalmente.
        DB::statement('ALTER TABLE stocks ADD CONSTRAINT stocks_quantities_non_negative
            CHECK (quantity_on_hand >= 0 AND quantity_reserved >= 0 AND average_cost >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE stocks DROP CONSTRAINT IF EXISTS stocks_quantities_non_negative');
        TenantTable::disableRls('stocks');
        Schema::dropIfExists('stocks');
    }
};
