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
     * Doc maestro 26.12: sync_batches + sync_operations. Persiste el
     * pipeline de POST /api/v1/sync/batch y salda el TODO de
     * SyncBatchController ("Idempotente por batch_uuid, cache en Fase 3"):
     * la idempotencia pasa a ser consulta real por uuid unique.
     *
     * Divergencias documentadas contra el DDL del maestro:
     * - device_id y branch_id NOT NULL en el DDL, pero el contrato 38.3
     *   del PWA no los envia: nullable hasta endurecer el contrato.
     * - sync_operations no lleva company_id en el DDL (acceso via batch):
     *   se respeta, sin RLS propio; el batch es la frontera tenant.
     * - Indice de sync_operations por batch_id adoptado (la cola del DDL
     *   no define indices y Postgres no indexa FKs automaticamente).
     */
    public function up(): void
    {
        Schema::create('sync_batches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique(); // idempotency key (38.3)
            TenantTable::companyColumn($table);

            $table->string('device_id', 100)->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();

            $table->integer('operations_count');
            $table->integer('success_count')->default(0);
            $table->integer('conflict_count')->default(0);
            $table->integer('error_count')->default(0);
            $table->string('status', 20)->default('processing');
            $table->timestampTz('received_at')->useCurrent();
            $table->timestampTz('completed_at')->nullable();
            $table->jsonb('request_payload');
            $table->jsonb('response_payload')->nullable();
            $table->text('error_message')->nullable();
        });

        TenantTable::enableRls('sync_batches');
        DB::statement('CREATE INDEX idx_sync_batches_device ON sync_batches(device_id, received_at DESC)');

        Schema::create('sync_operations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('batch_id');
            $table->foreign('batch_id')->references('id')->on('sync_batches')->cascadeOnDelete();
            $table->uuid('client_uuid');
            $table->string('entity_type', 60);
            $table->uuid('entity_uuid')->nullable();
            $table->string('operation', 20);
            $table->timestampTz('client_timestamp');
            $table->jsonb('payload');
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('server_id')->nullable();
            $table->uuid('server_uuid')->nullable();
            $table->jsonb('response')->nullable();
            $table->string('error_code', 40)->nullable();
            $table->text('error_message')->nullable();

            $table->index('batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_operations');
        DB::statement('DROP INDEX IF EXISTS idx_sync_batches_device');
        TenantTable::disableRls('sync_batches');
        Schema::dropIfExists('sync_batches');
    }
};
