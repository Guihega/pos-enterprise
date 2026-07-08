<?php

declare(strict_types=1);

namespace App\Domain\Reports\Services;

use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Consulta y refresca las vistas materializadas de reportes consolidados
 * (doc maestro 46.6).
 *
 * Aislamiento multi-tenant: las materialized views NO heredan las policies
 * RLS de las tablas base, por eso TODA consulta filtra explicitamente por
 * company_id = TenantContext::id(). Nunca se expone una MV sin ese filtro.
 */
final class ConsolidatedReportService
{
    /** @var list<string> */
    private const VIEWS = [
        'mv_sales_daily_global',
        'mv_inventory_global',
        'mv_branch_comparison',
    ];

    /**
     * Refresca las tres vistas. Refresh normal (no CONCURRENTLY) porque es
     * idempotente aunque la vista nunca se haya poblado; en produccion el
     * refresh CONCURRENTLY lo orquesta pg_cron sobre vistas ya pobladas.
     */
    public function refreshAll(): void
    {
        foreach (self::VIEWS as $view) {
            DB::statement("REFRESH MATERIALIZED VIEW {$view}");
        }
    }

    /**
     * Ventas globales por dia del tenant actual, mas reciente primero.
     *
     * @return list<array<string, mixed>>
     */
    public function salesDaily(?string $from = null, ?string $to = null): array
    {
        $query = DB::table('mv_sales_daily_global')
            ->where('company_id', TenantContext::id());

        if ($from !== null) {
            $query->where('sales_date', '>=', $from);
        }
        if ($to !== null) {
            $query->where('sales_date', '<=', $to);
        }

        return $query->orderByDesc('sales_date')->get()
            ->map(fn ($r) => [
                'date' => $r->sales_date,
                'sales_count' => (int) $r->sales_count,
                'total_amount' => (float) $r->total_amount,
                'subtotal_amount' => (float) $r->subtotal_amount,
                'tax_amount' => (float) $r->tax_amount,
                'discount_amount' => (float) $r->discount_amount,
            ])
            ->all();
    }

    /**
     * Stock total por producto cross-sucursal del tenant actual.
     *
     * @return list<array<string, mixed>>
     */
    public function inventoryGlobal(): array
    {
        return DB::table('mv_inventory_global')
            ->where('company_id', TenantContext::id())
            ->orderByDesc('total_on_hand')
            ->get()
            ->map(fn ($r) => [
                'product_id' => (int) $r->product_id,
                'total_on_hand' => (float) $r->total_on_hand,
                'total_reserved' => (float) $r->total_reserved,
                'warehouse_count' => (int) $r->warehouse_count,
            ])
            ->all();
    }

    /**
     * Comparativo de KPIs por sucursal del tenant actual.
     *
     * @return list<array<string, mixed>>
     */
    public function branchComparison(): array
    {
        return DB::table('mv_branch_comparison')
            ->where('company_id', TenantContext::id())
            ->orderByDesc('total_amount')
            ->get()
            ->map(fn ($r) => [
                'branch_id' => (int) $r->branch_id,
                'sales_count' => (int) $r->sales_count,
                'total_amount' => (float) $r->total_amount,
                'avg_ticket' => (float) $r->avg_ticket,
            ])
            ->all();
    }
}
