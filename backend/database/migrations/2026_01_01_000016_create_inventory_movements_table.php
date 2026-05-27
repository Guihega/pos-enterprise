<?php

declare(strict_types=1);

use App\Support\TenantTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kardex: registro inmutable de cada movimiento de inventario.
 *
 * Cada fila representa un cambio de cantidad en una combinación
 * (product_id, warehouse_id). Las filas son INMUTABLES: una vez insertadas,
 * nunca se actualizan ni borran (auditoría contable).
 *
 * Tipos:
 *   - entry            : entrada por compra
 *   - exit             : salida por venta
 *   - adjustment       : ajuste manual (conteo físico, merma, error)
 *   - transfer_out     : salida por transferencia entre warehouses
 *   - transfer_in      : entrada por transferencia
 *   - return_customer  : devolución de cliente (entrada)
 *   - return_supplier  : devolución a proveedor (salida)
 *   - production_in    : entrada por producción
 *   - production_out   : consumo en producción
 *   - opening          : saldo inicial al alta del producto
 *
 * "source_type" / "source_id": polimórfico hacia el documento origen
 * (Sale, PurchaseOrder, Adjustment, Transfer). Permite trazar de dónde
 * vino el movimiento.
 *
 * "transfer_id": para transferencias, agrupa la salida y entrada del par.
 *
 * "quantity_delta": positivo o negativo. Suma de todos los deltas de un
 * stock = quantity_on_hand actual (invariante verificable en jobs nightly).
 *
 * "quantity_after": snapshot del stock DESPUÉS de aplicar este movimiento.
 * Permite reconstruir kardex en cualquier punto del tiempo sin sumar
 * todo desde el principio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            TenantTable::companyColumn($table);

            $table->unsignedBigInteger('product_id');
            $table->foreign('product_id')
                ->references('id')->on('products')
                ->restrictOnDelete()  // No borrar productos con kardex
                ->comment('FK restrict: kardex preserva integridad histórica');

            $table->unsignedBigInteger('warehouse_id');
            $table->foreign('warehouse_id')
                ->references('id')->on('warehouses')
                ->restrictOnDelete();

            // Sucursal (denormalizado: warehouse->branch). Útil para reportes
            // por sucursal sin join.
            $table->unsignedBigInteger('branch_id');
            $table->foreign('branch_id')
                ->references('id')->on('branches')
                ->restrictOnDelete();

            $table->enum('type', [
                'entry', 'exit', 'adjustment',
                'transfer_out', 'transfer_in',
                'return_customer', 'return_supplier',
                'production_in', 'production_out',
                'opening',
            ]);

            // Polimórfico hacia el documento origen (Sale, Adjustment, etc.)
            $table->string('source_type', 100)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            // Transferencias: liga out con in
            $table->uuid('transfer_id')->nullable()
                ->comment('UUID que agrupa transfer_out + transfer_in del mismo movimiento');

            // Cantidades. delta puede ser negativo, after siempre >= 0.
            $table->decimal('quantity_delta', 18, 4);
            $table->decimal('quantity_after', 18, 4);

            // Costo (para kardex valorizado)
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->decimal('total_cost', 18, 4)->default(0);
            $table->decimal('average_cost_after', 18, 4)->default(0)
                ->comment('Costo promedio ponderado del stock DESPUÉS del movimiento');

            // Documentación
            $table->string('reason', 500)->nullable()
                ->comment('Motivo (obligatorio para ajustes manuales)');
            $table->string('reference', 100)->nullable()
                ->comment('Folio externo (factura proveedor, ticket, etc.)');

            // Auditoría
            $table->unsignedBigInteger('user_id')->nullable()
                ->comment('Usuario que ejecutó (null si fue sistema)');
            $table->foreign('user_id')
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->jsonb('metadata')->default('{}');

            $table->timestampTz('movement_at')
                ->comment('Fecha del movimiento (puede diferir de created_at en correcciones)');
            $table->timestampsTz();

            // Índices para queries de kardex
            $table->index(['company_id', 'product_id', 'movement_at'], 'idx_movements_product_time');
            $table->index(['company_id', 'warehouse_id', 'movement_at'], 'idx_movements_warehouse_time');
            $table->index(['company_id', 'type']);
            $table->index(['source_type', 'source_id'], 'idx_movements_source');
            $table->index('transfer_id');
        });

        TenantTable::enableRls('inventory_movements');

        // Check: quantity_after no negativo (lo principal: aborta cualquier
        // intento de quedar el stock < 0). El delta sí puede ser negativo.
        DB::statement('ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_after_non_negative
            CHECK (quantity_after >= 0)');

        // Trigger guard: BLOQUEA UPDATE/DELETE en kardex (inmutabilidad).
        // Solo INSERT permitido.
        DB::statement("
            CREATE OR REPLACE FUNCTION inventory_movements_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'inventory_movements is immutable: % not allowed', TG_OP;
            END;
            $$ LANGUAGE plpgsql;
        ");

        DB::statement('
            CREATE TRIGGER inventory_movements_no_update
            BEFORE UPDATE ON inventory_movements
            FOR EACH ROW EXECUTE FUNCTION inventory_movements_immutable()
        ');

        DB::statement('
            CREATE TRIGGER inventory_movements_no_delete
            BEFORE DELETE ON inventory_movements
            FOR EACH ROW EXECUTE FUNCTION inventory_movements_immutable()
        ');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS inventory_movements_no_update ON inventory_movements');
        DB::statement('DROP TRIGGER IF EXISTS inventory_movements_no_delete ON inventory_movements');
        DB::statement('DROP FUNCTION IF EXISTS inventory_movements_immutable()');
        DB::statement('ALTER TABLE inventory_movements DROP CONSTRAINT IF EXISTS inventory_movements_after_non_negative');
        TenantTable::disableRls('inventory_movements');
        Schema::dropIfExists('inventory_movements');
    }
};
