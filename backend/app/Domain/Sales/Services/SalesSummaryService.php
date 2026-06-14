<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleItem;
use App\Domain\Sales\Models\SalePayment;
use App\Domain\Tenancy\Models\Branch;
use Carbon\CarbonImmutable;

/**
 * Calcula el resumen de ventas de un dia (status completed).
 *
 * Alimenta tanto el Reporte de ventas del dia como el Dashboard simple.
 */
final class SalesSummaryService
{
    public function forDate(string $date, ?string $branchUuid = null): array
    {
        $day = CarbonImmutable::parse($date);
        $from = $day->startOfDay();
        $to = $day->endOfDay();

        $branch = $branchUuid !== null
            ? Branch::query()->where('uuid', $branchUuid)->first()
            : null;

        $branchId = $branch?->id;

        return [
            'date' => $day->toDateString(),
            'branch' => $branch !== null ? [
                'uuid' => $branch->uuid,
                'code' => $branch->code,
                'name' => $branch->name,
            ] : null,
            'totals' => $this->totals($from, $to, $branchId),
            'payments' => $this->paymentBreakdown($from, $to, $branchId),
            'top_products' => $this->topProducts($from, $to, $branchId),
        ];
    }

    private function totals(CarbonImmutable $from, CarbonImmutable $to, ?int $branchId): array
    {
        $query = Sale::query()
            ->completed()
            ->whereBetween('completed_at', [$from, $to]);

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        $row = $query->selectRaw('
            COUNT(*) as sales_count,
            COALESCE(SUM(total_amount), 0) as gross_amount,
            COALESCE(SUM(subtotal_amount), 0) as subtotal_amount,
            COALESCE(SUM(discount_amount), 0) as discount_amount,
            COALESCE(SUM(tax_amount), 0) as tax_amount
        ')->first();

        $count = (int) ($row->sales_count ?? 0);
        $gross = (float) ($row->gross_amount ?? 0);

        return [
            'sales_count' => $count,
            'gross_amount' => round($gross, 2),
            'subtotal_amount' => round((float) ($row->subtotal_amount ?? 0), 2),
            'discount_amount' => round((float) ($row->discount_amount ?? 0), 2),
            'tax_amount' => round((float) ($row->tax_amount ?? 0), 2),
            'average_ticket' => $count > 0 ? round($gross / $count, 2) : 0.0,
        ];
    }

    /**
     * Desglose por metodo de pago (de las ventas completadas del dia).
     *
     * @return list<array{method: string, count: int, amount: float}>
     */
    private function paymentBreakdown(CarbonImmutable $from, CarbonImmutable $to, ?int $branchId): array
    {
        $query = SalePayment::query()
            ->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->where('sales.status', Sale::STATUS_COMPLETED)
            ->whereBetween('sales.completed_at', [$from, $to]);

        if ($branchId !== null) {
            $query->where('sales.branch_id', $branchId);
        }

        return $query
            ->groupBy('sale_payments.method')
            ->orderByDesc('amount')
            ->get([
                'sale_payments.method as method',
                \DB::raw('COUNT(*) as count'),
                \DB::raw('COALESCE(SUM(sale_payments.amount), 0) as amount'),
            ])
            ->map(fn ($r) => [
                'method' => (string) $r->method,
                'count' => (int) $r->count,
                'amount' => round((float) $r->amount, 2),
            ])
            ->all();
    }

    /**
     * Top productos del dia por monto vendido (line_total).
     *
     * @return list<array{product_uuid: string|null, sku: string, name: string, quantity: float, amount: float}>
     */
    private function topProducts(CarbonImmutable $from, CarbonImmutable $to, ?int $branchId, int $limit = 5): array
    {
        $query = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->leftJoin('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sales.status', Sale::STATUS_COMPLETED)
            ->whereBetween('sales.completed_at', [$from, $to]);

        if ($branchId !== null) {
            $query->where('sales.branch_id', $branchId);
        }

        return $query
            ->groupBy('sale_items.product_id', 'products.uuid', 'sale_items.product_sku', 'sale_items.product_name')
            ->orderByDesc('amount')
            ->limit($limit)
            ->get([
                'products.uuid as product_uuid',
                'sale_items.product_sku as sku',
                'sale_items.product_name as name',
                \DB::raw('COALESCE(SUM(sale_items.quantity), 0) as quantity'),
                \DB::raw('COALESCE(SUM(sale_items.line_total), 0) as amount'),
            ])
            ->map(fn ($r) => [
                'product_uuid' => $r->product_uuid !== null ? (string) $r->product_uuid : null,
                'sku' => (string) $r->sku,
                'name' => (string) $r->name,
                'quantity' => round((float) $r->quantity, 4),
                'amount' => round((float) $r->amount, 2),
            ])
            ->all();
    }
}
