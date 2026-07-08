<?php

declare(strict_types=1);

use App\Support\TenantTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Solicitudes de transferencia inter-sucursal (doc maestro CU-GER-003).
 *
 * Un gerente que ve stock en otra sucursal (RN-233) solicita mercancia.
 * El gerente de la sucursal ORIGEN aprueba o rechaza. Si aprueba, se crea
 * el Transfer (draft) y la FSM 14.5 de Transfer manda desde ahi.
 *
 * FSM propia (el maestro no define estado de aprobacion en Transfer 14.5;
 * la solicitud es un documento separado):
 *   pending -> approved | rejected | cancelled (terminales)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfer_requests', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            TenantTable::companyColumn($table);

            // Folio unico por tenant.
            $table->string('folio', 40);

            // Origen: sucursal que TIENE el stock y aprueba.
            // Destino: sucursal que solicita y recibiria la mercancia.
            $table->unsignedBigInteger('from_branch_id');
            $table->foreign('from_branch_id')
                ->references('id')->on('branches')
                ->restrictOnDelete();
            $table->unsignedBigInteger('to_branch_id');
            $table->foreign('to_branch_id')
                ->references('id')->on('branches')
                ->restrictOnDelete();

            $table->string('status', 20)->default('pending');

            // Solicitante (gerente de la sucursal destino).
            $table->unsignedBigInteger('requested_by_user_id');
            $table->foreign('requested_by_user_id')
                ->references('id')->on('users')
                ->restrictOnDelete();

            // Resolucion (aprobacion o rechazo por gerente de origen;
            // cancelacion por el solicitante).
            $table->unsignedBigInteger('resolved_by_user_id')->nullable();
            $table->foreign('resolved_by_user_id')
                ->references('id')->on('users')
                ->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->text('rejection_reason')->nullable();

            // Transfer creado al aprobar.
            $table->unsignedBigInteger('transfer_id')->nullable();
            $table->foreign('transfer_id')
                ->references('id')->on('transfers')
                ->nullOnDelete();

            $table->text('notes')->nullable();

            $table->timestampsTz();

            $table->unique(['company_id', 'folio']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'from_branch_id', 'created_at'], 'idx_transfer_requests_from');
            $table->index(['company_id', 'to_branch_id', 'created_at'], 'idx_transfer_requests_to');
        });

        TenantTable::enableRls('transfer_requests');

        DB::statement('ALTER TABLE transfer_requests ADD CONSTRAINT transfer_requests_distinct_branches
            CHECK (from_branch_id <> to_branch_id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE transfer_requests DROP CONSTRAINT IF EXISTS transfer_requests_distinct_branches');
        TenantTable::disableRls('transfer_requests');
        Schema::dropIfExists('transfer_requests');
    }
};
