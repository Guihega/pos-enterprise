<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Crea las funciones PostgreSQL necesarias para RLS.
 * En desarrollo se cargan via docker/postgres/init/01-init.sql,
 * pero en CI y produccion deben existir antes de las migraciones de tablas.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION current_tenant_id()
            RETURNS BIGINT
            LANGUAGE plpgsql
            STABLE
            AS $$
            BEGIN
                RETURN COALESCE(
                    NULLIF(current_setting('app.current_tenant_id', TRUE), '')::BIGINT,
                    0
                );
            EXCEPTION WHEN OTHERS THEN
                RETURN 0;
            END;
            $$;

            COMMENT ON FUNCTION current_tenant_id() IS
                'Devuelve el tenant_id de la sesion actual o 0 si no hay contexto. Usado por politicas RLS.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS current_tenant_id()');
    }
};
