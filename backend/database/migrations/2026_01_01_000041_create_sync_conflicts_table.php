<?php

declare(strict_types=1);

use App\Support\TenantTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Doc maestro 26.12 (linea 5262): sync_conflicts, cola de conflictos
     * para resolucion humana (sec. 39.3, RN-156). Fiel al DDL con una
     * divergencia documentada (mismo criterio que 000040):
     * - device_id NOT NULL en el DDL, pero el contrato 38.3 del PWA no
     *   lo garantiza (campo opcional del batch): nullable hasta endurecer
     *   el contrato.
     * Indice parcial de no-resueltos adoptado del DDL.
     */
    public function up(): void
    {
        Schema::create('sync_conflicts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            TenantTable::companyColumn($table);

            $table->unsignedBigInteger('branch_id');
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->string('device_id', 100)->nullable();
            $table->unsignedBigInteger('sync_operation_id')->nullable();
            $table->foreign('sync_operation_id')->references('id')->on('sync_operations')->nullOnDelete();

            $table->string('entity_type', 60);
            $table->uuid('entity_uuid');
            $table->string('conflict_type', 40);
            $table->jsonb('client_data');
            $table->jsonb('server_data');

            $table->string('resolution', 40)->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->foreign('resolved_by')->references('id')->on('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        TenantTable::enableRls('sync_conflicts');
        DB::statement('CREATE INDEX idx_sync_conflicts_unresolved ON sync_conflicts(company_id, created_at) WHERE resolved_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_sync_conflicts_unresolved');
        TenantTable::disableRls('sync_conflicts');
        Schema::dropIfExists('sync_conflicts');
    }
};
