<?php

declare(strict_types=1);

use App\Support\TenantTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Transferencias inter-sucursal (doc maestro 46.4 y 14.5).
 *
 * Documento de traslado de mercancia entre dos sucursales del mismo tenant.
 * Maquina de estados (14.5):
 *   draft -> sent (descuenta stock origen)
 *   draft -> cancelled
 *   sent  -> received (captura cantidad recibida; merma => ajuste transfer_loss, RN-049)
 *   sent  -> returned_to_origin (rechazada, devuelve a origen)
 *   returned_to_origin -> cancelled
 *
 * Los movimientos de stock (transfer_out al enviar, transfer_in al recibir)
 * se registran en inventory_movements, ligados por su columna transfer_id y
 * con source_type='Transfer' / source_id=transfers.id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfers', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            TenantTable::companyColumn($table);

            // Folio unico por tenant.
            $table->string('folio', 40);

            // Sucursales origen y destino.
            $table->unsignedBigInteger('from_branch_id');
            $table->foreign('from_branch_id')
                ->references('id')->on('branches')
                ->restrictOnDelete();
            $table->unsignedBigInteger('to_branch_id');
            $table->foreign('to_branch_id')
                ->references('id')->on('branches')
                ->restrictOnDelete();

            // Almacenes origen y destino (opcionales: la sucursal puede tener
            // un almacen default; se resuelve al enviar/recibir).
            $table->unsignedBigInteger('from_warehouse_id')->nullable();
            $table->foreign('from_warehouse_id')
                ->references('id')->on('warehouses')
                ->nullOnDelete();
            $table->unsignedBigInteger('to_warehouse_id')->nullable();
            $table->foreign('to_warehouse_id')
                ->references('id')->on('warehouses')
                ->nullOnDelete();

            // Estado (FSM validada en codigo; ver TransferService).
            $table->string('status', 20)->default('draft');

            // Trazabilidad de envio.
            $table->unsignedBigInteger('sent_by_user_id')->nullable();
            $table->foreign('sent_by_user_id')
                ->references('id')->on('users')
                ->nullOnDelete();
            $table->timestampTz('sent_at')->nullable();

            // Trazabilidad de recepcion.
            $table->unsignedBigInteger('received_by_user_id')->nullable();
            $table->foreign('received_by_user_id')
                ->references('id')->on('users')
                ->nullOnDelete();
            $table->timestampTz('received_at')->nullable();

            // Trazabilidad de cancelacion.
            $table->timestampTz('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->foreign('cancelled_by')
                ->references('id')->on('users')
                ->nullOnDelete();
            $table->text('cancellation_reason')->nullable();

            // Transporte.
            $table->string('transport_method', 60)->nullable();
            $table->string('transport_reference', 120)->nullable();

            $table->text('notes')->nullable();
            $table->decimal('total_cost', 14, 2)->default(0);

            $table->timestampsTz();

            $table->unique(['company_id', 'folio']);
            $table->index(['company_id', 'from_branch_id', 'created_at'], 'idx_transfers_from_branch');
            $table->index(['company_id', 'to_branch_id', 'created_at'], 'idx_transfers_to_branch');
            $table->index(['company_id', 'status']);
        });

        TenantTable::enableRls('transfers');

        // Origen y destino deben ser sucursales distintas.
        DB::statement('ALTER TABLE transfers ADD CONSTRAINT transfers_distinct_branches
            CHECK (from_branch_id <> to_branch_id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE transfers DROP CONSTRAINT IF EXISTS transfers_distinct_branches');
        TenantTable::disableRls('transfers');
        Schema::dropIfExists('transfers');
    }
};
