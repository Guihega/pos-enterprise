<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * Helper para aplicar el patrón estándar de Row Level Security (RLS) sobre
 * tablas tenant-aware.
 *
 * Decisión: ADR-0006. Cada tabla con `company_id` debe pasar por aquí para
 * garantizar que tres barreras de aislamiento están activas:
 *
 *  1. Política RLS principal: filtrado por `current_tenant_id()`.
 *  2. Política bypass para super_admin (panel SaaS).
 *  3. FOREIGN KEY a `companies(id)` con ON DELETE RESTRICT (no se borra
 *     un tenant accidentalmente si tiene datos).
 *
 * Uso típico en una migración:
 *
 *   public function up(): void
 *   {
 *       Schema::create('products', function (Blueprint $t) {
 *           $t->bigIncrements('id');
 *           $t->uuid('uuid')->unique();
 *           TenantTable::companyColumn($t);   // company_id BIGINT NOT NULL FK
 *           // ... resto de columnas
 *           $t->timestampsTz();
 *           $t->softDeletesTz();
 *       });
 *
 *       TenantTable::enableRls('products');
 *   }
 */
final class TenantTable
{
    /**
     * Agrega la columna `company_id` con FK e índice estándar.
     * Debe llamarse dentro del closure de Schema::create / Schema::table.
     */
    public static function companyColumn(Blueprint $table): void
    {
        $table->unsignedBigInteger('company_id');
        $table->foreign('company_id')
            ->references('id')
            ->on('companies')
            ->cascadeOnUpdate()
            ->restrictOnDelete();
        $table->index('company_id');
    }

    /**
     * Activa RLS y crea las dos políticas estándar (aislamiento + bypass admin)
     * sobre la tabla indicada.
     */
    public static function enableRls(string $table): void
    {
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");

        // El owner del schema (típicamente "pos") siempre debe poder leer en
        // procesos administrativos (migraciones, seeders). FORCE RLS lo
        // sometería incluso a él.
        DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");

        // Política 1: aislamiento por tenant.
        DB::statement("
            CREATE POLICY {$table}_tenant_isolation ON {$table}
            USING (company_id = current_tenant_id())
            WITH CHECK (company_id = current_tenant_id())
        ");

        // Política 2: bypass para super_admin (panel SaaS).
        DB::statement("
            CREATE POLICY {$table}_super_admin_bypass ON {$table}
            USING (current_setting('app.is_super_admin', TRUE) = 'true')
            WITH CHECK (current_setting('app.is_super_admin', TRUE) = 'true')
        ");
    }

    /**
     * Aplica RLS pero más estricta: FORCE ROW LEVEL SECURITY hace que ni el
     * owner pueda saltarse las políticas. Útil para tablas de auditoría.
     */
    public static function enableStrictRls(string $table): void
    {
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");

        DB::statement("
            CREATE POLICY {$table}_tenant_isolation ON {$table}
            USING (company_id = current_tenant_id())
            WITH CHECK (company_id = current_tenant_id())
        ");

        DB::statement("
            CREATE POLICY {$table}_super_admin_bypass ON {$table}
            USING (current_setting('app.is_super_admin', TRUE) = 'true')
            WITH CHECK (current_setting('app.is_super_admin', TRUE) = 'true')
        ");
    }

    /**
     * Quita RLS y políticas. Usar solo en `down()` de migraciones.
     */
    public static function disableRls(string $table): void
    {
        DB::statement("DROP POLICY IF EXISTS {$table}_tenant_isolation ON {$table}");
        DB::statement("DROP POLICY IF EXISTS {$table}_super_admin_bypass ON {$table}");
        DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
    }
}
