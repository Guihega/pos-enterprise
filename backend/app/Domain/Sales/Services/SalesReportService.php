<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleItem;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Services\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Reportes operativos de ventas sobre un RANGO de fechas.
 *
 * Distinto de SalesSummaryService, que resuelve el resumen de UN dia y devuelve
 * un top acotado (top_products, limit 5). Aqui el eje es el rango y la lista es
 * completa: 'que se vendio este mes', no 'top 5 de hoy'.
 *
 * El numero de filas lo acota el catalogo (productos o cajeros del tenant), no la
 * longitud del rango, por eso no se impone un tope de dias. El parametro limit
 * queda disponible para recortar.
 *
 * Los joins a users son crudos a proposito: users usa SoftDeletes y las ventas de
 * un cajero dado de baja deben seguir contando en el historico.
 */
final class SalesReportService
{
    /**
     * Ventas agregadas por producto en el rango.
     */
    public function byProduct(string $from, string $to, ?string $branchUuid = null, ?int $limit = null): array
    {
        [$start, $end, $branch] = $this->resolve($from, $to, $branchUuid);

        $query = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->leftJoin('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sales.status', Sale::STATUS_COMPLETED)
            ->whereBetween('sales.completed_at', [$start, $end]);

        if ($branch !== null) {
            $query->where('sales.branch_id', $branch->id);
        }

        $query->groupBy('sale_items.product_id', 'products.uuid', 'sale_items.product_sku', 'sale_items.product_name')
            ->orderByDesc('amount');

        if ($limit !== null) {
            $query->limit($limit);
        }

        $rows = $query->get([
            'products.uuid as product_uuid',
            'sale_items.product_sku as sku',
            'sale_items.product_name as name',
            \DB::raw('COUNT(DISTINCT sales.id) as sales_count'),
            \DB::raw('COALESCE(SUM(sale_items.quantity), 0) as quantity'),
            \DB::raw('COALESCE(SUM(sale_items.line_total), 0) as amount'),
        ])->map(fn ($r) => [
            'product_uuid' => $r->product_uuid !== null ? (string) $r->product_uuid : null,
            'sku' => (string) $r->sku,
            'name' => (string) $r->name,
            'sales_count' => (int) $r->sales_count,
            'quantity' => round((float) $r->quantity, 4),
            'amount' => round((float) $r->amount, 2),
        ])->all();

        return $this->envelope($start, $end, $branch, $rows);
    }

    /**
     * Ventas agregadas por cajero (users.id via sales.user_id) en el rango.
     */
    public function byCashier(string $from, string $to, ?string $branchUuid = null, ?int $limit = null): array
    {
        [$start, $end, $branch] = $this->resolve($from, $to, $branchUuid);

        $query = Sale::query()
            ->join('users', 'users.id', '=', 'sales.user_id')
            ->completed()
            ->whereBetween('sales.completed_at', [$start, $end]);

        if ($branch !== null) {
            $query->where('sales.branch_id', $branch->id);
        }

        $query->groupBy('sales.user_id', 'users.uuid', 'users.name')
            ->orderByDesc('amount');

        if ($limit !== null) {
            $query->limit($limit);
        }

        $rows = $query->get([
            'users.uuid as user_uuid',
            'users.name as name',
            \DB::raw('COUNT(*) as sales_count'),
            \DB::raw('COALESCE(SUM(sales.total_amount), 0) as amount'),
            \DB::raw('COALESCE(SUM(sales.discount_amount), 0) as discount_amount'),
        ])->map(function ($r) {
            $count = (int) $r->sales_count;
            $amount = round((float) $r->amount, 2);

            return [
                'user_uuid' => (string) $r->user_uuid,
                'name' => (string) $r->name,
                'sales_count' => $count,
                'amount' => $amount,
                'discount_amount' => round((float) $r->discount_amount, 2),
                'average_ticket' => $count > 0 ? round($amount / $count, 2) : 0.0,
            ];
        })->all();

        return $this->envelope($start, $end, $branch, $rows);
    }

    /**
     * Productos activos y vendibles SIN ventas en el rango.
     *
     * Antijoin con NOT EXISTS: se listan los productos del catalogo que no
     * aparecen en ningun sale_item de una venta COMPLETED dentro del rango.
     *
     * last_sold_at mira el historico COMPLETO, sin acotar al rango ni a la
     * sucursal: distingue 'nunca se vendio' (null) de 'no se vendio este mes
     * pero si en marzo'. Es el dato que hace accionable el reporte.
     *
     * El company_id de la subconsulta va EXPLICITO. El global scope de
     * BelongsToTenant solo alcanza al query base (products); una subconsulta
     * cruda sobre sales/sale_items no lo hereda. RLS lo cubriria, pero el
     * filtro explicito no depende de que la variable de sesion este puesta.
     *
     * totals.amount del envelope es SIEMPRE 0: por definicion estas filas no
     * tienen importe vendido. Se reusa el envelope por consistencia de
     * contrato con los otros reportes por rango.
     */
    public function productsWithoutSales(string $from, string $to, ?string $branchUuid = null, ?int $limit = null): array
    {
        [$start, $end, $branch] = $this->resolve($from, $to, $branchUuid);
        $companyId = TenantContext::id();

        $lastSold = \DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->selectRaw('MAX(s.completed_at)')
            ->whereColumn('si.product_id', 'products.id')
            ->where('s.company_id', $companyId)
            ->where('s.status', Sale::STATUS_COMPLETED);

        $query = Product::query()
            ->active()
            ->sellable()
            ->select(['products.uuid', 'products.sku', 'products.name', 'products.price'])
            ->selectSub($lastSold, 'last_sold_at')
            ->whereNotExists(function ($sub) use ($start, $end, $branch, $companyId): void {
                $sub->select(\DB::raw(1))
                    ->from('sale_items')
                    ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                    ->whereColumn('sale_items.product_id', 'products.id')
                    ->where('sales.company_id', $companyId)
                    ->where('sales.status', Sale::STATUS_COMPLETED)
                    ->whereBetween('sales.completed_at', [$start, $end]);

                if ($branch !== null) {
                    $sub->where('sales.branch_id', $branch->id);
                }
            })
            ->orderBy('products.sku');

        if ($limit !== null) {
            $query->limit($limit);
        }

        $rows = $query->get()->map(fn ($p) => [
            'product_uuid' => (string) $p->uuid,
            'sku' => (string) $p->sku,
            'name' => (string) $p->name,
            'price' => round((float) $p->price, 2),
            'amount' => 0.0,
            'last_sold_at' => $p->last_sold_at !== null
                ? CarbonImmutable::parse($p->last_sold_at)->toDateString() : null,
        ])->all();

        return $this->envelope($start, $end, $branch, $rows);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: Branch|null}
     */
    private function resolve(string $from, string $to, ?string $branchUuid): array
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->endOfDay();

        $branch = $branchUuid !== null
            ? Branch::query()->where('uuid', $branchUuid)->first()
            : null;

        return [$start, $end, $branch];
    }

    private function envelope(CarbonImmutable $start, CarbonImmutable $end, ?Branch $branch, array $rows): array
    {
        return [
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'branch' => $branch !== null ? [
                'uuid' => $branch->uuid,
                'code' => $branch->code,
                'name' => $branch->name,
            ] : null,
            'totals' => [
                'rows_count' => count($rows),
                'amount' => round(array_sum(array_column($rows, 'amount')), 2),
            ],
            'rows' => $rows,
        ];
    }
}
