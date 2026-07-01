<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Vistas materializadas de reportes consolidados (doc maestro 46.6).
 *
 * Agregan datos de TODAS las sucursales de cada tenant. El aislamiento
 * multi-tenant se garantiza por dos vias: (1) cada vista incluye company_id
 * en su agrupacion; (2) el servicio que las consulta SIEMPRE filtra por
 * current_tenant_id() / TenantContext::id(). Las materialized views no heredan
 * las policies RLS de las tablas base, por eso el filtro explicito es
 * obligatorio en la capa de consulta.
 *
 * Refresco: en produccion via pg_cron cada 15 min; en dev/app via el comando
 * artisan reports:refresh-consolidated. Cada vista tiene indice UNICO para
 * permitir REFRESH ... CONCURRENTLY (no bloquea lecturas).
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Ventas globales por tenant por dia (solo ventas completadas).
        DB::statement("
            CREATE MATERIALIZED VIEW mv_sales_daily_global AS
            SELECT
                company_id,
                (completed_at AT TIME ZONE 'UTC')::date AS sales_date,
                COUNT(*) AS sales_count,
                COALESCE(SUM(total_amount), 0) AS total_amount,
                COALESCE(SUM(subtotal_amount), 0) AS subtotal_amount,
                COALESCE(SUM(tax_amount), 0) AS tax_amount,
                COALESCE(SUM(discount_amount), 0) AS discount_amount
            FROM sales
            WHERE status = 'completed' AND completed_at IS NOT NULL
            GROUP BY company_id, (completed_at AT TIME ZONE 'UTC')::date
            WITH NO DATA
        ");
        DB::statement('CREATE UNIQUE INDEX mv_sales_daily_global_uidx
            ON mv_sales_daily_global (company_id, sales_date)');

        // 2. Stock total por producto cross-sucursal.
        DB::statement('
            CREATE MATERIALIZED VIEW mv_inventory_global AS
            SELECT
                s.company_id,
                s.product_id,
                COALESCE(SUM(s.quantity_on_hand), 0) AS total_on_hand,
                COALESCE(SUM(s.quantity_reserved), 0) AS total_reserved,
                COUNT(DISTINCT s.warehouse_id) AS warehouse_count
            FROM stocks s
            GROUP BY s.company_id, s.product_id
            WITH NO DATA
        ');
        DB::statement('CREATE UNIQUE INDEX mv_inventory_global_uidx
            ON mv_inventory_global (company_id, product_id)');

        // 3. Comparativo de KPIs por sucursal (ventas completadas).
        DB::statement("
            CREATE MATERIALIZED VIEW mv_branch_comparison AS
            SELECT
                company_id,
                branch_id,
                COUNT(*) AS sales_count,
                COALESCE(SUM(total_amount), 0) AS total_amount,
                COALESCE(AVG(total_amount), 0) AS avg_ticket
            FROM sales
            WHERE status = 'completed' AND completed_at IS NOT NULL
            GROUP BY company_id, branch_id
            WITH NO DATA
        ");
        DB::statement('CREATE UNIQUE INDEX mv_branch_comparison_uidx
            ON mv_branch_comparison (company_id, branch_id)');
    }

    public function down(): void
    {
        DB::statement('DROP MATERIALIZED VIEW IF EXISTS mv_branch_comparison');
        DB::statement('DROP MATERIALIZED VIEW IF EXISTS mv_inventory_global');
        DB::statement('DROP MATERIALIZED VIEW IF EXISTS mv_sales_daily_global');
    }
};
