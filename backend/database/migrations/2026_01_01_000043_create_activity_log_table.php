<?php

declare(strict_types=1);

use App\Support\TenantTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Maestro 26.13: tabla activity_log (RN-170: toda accion modificante
 * se registra; RN-171: no se actualiza ni elimina).
 *
 * Fiel al DDL en columnas e indices. Divergencias documentadas:
 *
 * 1. Sin particionado RANGE mensual (RN-172): exige crear particiones
 *    por adelantado (job recurrente + gestion operativa) y esta ligado
 *    al archivado a Spaces (RN-173, infra externa). Diferido junto con
 *    RN-173; reabrir por volumen. Tabla normal con los mismos indices.
 * 2. Inmutabilidad (RN-171) via trigger que aborta UPDATE/DELETE, en
 *    vez del REVOKE del maestro: el usuario de la app (pos) es owner
 *    del schema y el REVOKE no frena al owner. El trigger garantiza
 *    la regla a nivel BD para cualquier rol, y es verificable en tests.
 * 3. company_id NOT NULL (el DDL lo permite NULL para eventos de
 *    sistema): bajo la politica RLS estandar una fila NULL seria
 *    invisible para todo tenant. Todos los productores actuales son
 *    tenant-scoped; eventos de sistema sin tenant diferidos.
 *
 * RLS estricta (enableStrictRls, FORCE): tabla de auditoria, ni el
 * owner se salta las politicas (docblock de TenantTable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->uuid('uuid')->unique();
            TenantTable::companyColumn($t);
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->foreign('branch_id')->references('id')->on('branches');
            $t->string('log_name', 60);
            $t->text('description');
            $t->string('subject_type', 180)->nullable();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->string('causer_type', 180)->nullable();
            $t->unsignedBigInteger('causer_id')->nullable();
            $t->string('causer_name', 180)->nullable();
            $t->string('event', 60);
            $t->jsonb('properties')->nullable();
            $t->uuid('batch_uuid')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->string('device_id', 100)->nullable();
            $t->string('request_id', 100)->nullable();
            $t->string('severity', 20)->default('info');
            $t->timestampTz('created_at')->useCurrent();

            $t->index(['subject_type', 'subject_id', 'created_at'], 'idx_activity_log_subject');
            $t->index(['company_id', 'event', 'created_at'], 'idx_activity_log_company_event');
        });

        DB::statement('CREATE INDEX idx_activity_log_causer ON activity_log (causer_id, created_at DESC) WHERE causer_id IS NOT NULL');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION activity_log_immutable()
            RETURNS trigger AS $fn$
            BEGIN
                RAISE EXCEPTION 'activity_log es inmutable (RN-171)';
            END;
            $fn$ LANGUAGE plpgsql
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER trg_activity_log_immutable
            BEFORE UPDATE OR DELETE ON activity_log
            FOR EACH ROW EXECUTE FUNCTION activity_log_immutable()
            SQL);

        TenantTable::enableStrictRls('activity_log');
    }

    public function down(): void
    {
        TenantTable::disableRls('activity_log');
        DB::statement('DROP TRIGGER IF EXISTS trg_activity_log_immutable ON activity_log');
        DB::statement('DROP FUNCTION IF EXISTS activity_log_immutable()');
        Schema::dropIfExists('activity_log');
    }
};
