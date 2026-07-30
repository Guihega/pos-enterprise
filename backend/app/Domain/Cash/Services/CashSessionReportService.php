<?php

declare(strict_types=1);

namespace App\Domain\Cash\Services;

use App\Domain\Cash\Models\CashMovement;
use App\Domain\Cash\Models\CashSession;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SalePayment;
use App\Domain\Tenancy\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Corte de caja (X/Z) de una sesion.
 *
 * El mismo calculo sirve para:
 *   - Corte X: sesion status=open, "en vivo" (counted_amount/difference null).
 *   - Corte Z: sesion status=closed, refleja el cierre ya persistido.
 *
 * Reutiliza CashService::cashAffectingDelta() para expected_amount, el
 * mismo calculo que usa closeSession().
 */
final class CashSessionReportService
{
    public function __construct(
        private readonly CashService $cash,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(CashSession $session): array
    {
        $session->loadMissing(['register', 'openedBy', 'closedBy'])
            ->loadCount('movements');

        return [
            'session' => $session,
            'sales' => $this->salesSummary($session),
            'payments' => $this->paymentBreakdown($session),
            'movements' => $this->movementBreakdown($session),
            'cash' => $this->cashSummary($session),
        ];
    }

    /**
     * @return array{count: int, total_amount: float}
     */
    private function salesSummary(CashSession $session): array
    {
        $row = Sale::query()
            ->ofSession($session->id)
            ->completed()
            ->selectRaw('COUNT(*) as sales_count, COALESCE(SUM(total_amount), 0) as total_amount')
            ->first();

        return [
            'count' => (int) ($row->sales_count ?? 0),
            'total_amount' => round((float) ($row->total_amount ?? 0), 2),
        ];
    }

    /**
     * @return list<array{method: string, count: int, amount: float}>
     */
    private function paymentBreakdown(CashSession $session): array
    {
        return SalePayment::query()
            ->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->where('sales.cash_session_id', $session->id)
            ->where('sales.status', Sale::STATUS_COMPLETED)
            ->groupBy('sale_payments.method')
            ->orderByDesc('amount')
            ->get([
                'sale_payments.method as method',
                DB::raw('COUNT(*) as count'),
                DB::raw('COALESCE(SUM(sale_payments.amount), 0) as amount'),
            ])
            ->map(fn ($r) => [
                'method' => (string) $r->method,
                'count' => (int) $r->count,
                'amount' => round((float) $r->amount, 2),
            ])
            ->all();
    }

    /**
     * Desglose de movimientos de caja por tipo (cash_in, cash_out,
     * sale_cash, refund_cash, sale_other, tip, adjustment).
     *
     * @return list<array{type: string, count: int, amount: float, delta_signed: float}>
     */
    private function movementBreakdown(CashSession $session): array
    {
        return CashMovement::query()
            ->where('cash_session_id', $session->id)
            ->groupBy('type')
            ->orderBy('type')
            ->get([
                'type',
                DB::raw('COUNT(*) as count'),
                DB::raw('COALESCE(SUM(amount), 0) as amount'),
                DB::raw('COALESCE(SUM(delta_signed), 0) as delta_signed'),
            ])
            ->map(fn ($r) => [
                'type' => (string) $r->type,
                'count' => (int) $r->count,
                'amount' => round((float) $r->amount, 2),
                'delta_signed' => round((float) $r->delta_signed, 2),
            ])
            ->all();
    }

    /**
     * Resumen de efectivo: apertura, delta de movimientos que afectan
     * caja fisica, esperado, y (si la sesion ya cerro) contado/diferencia.
     *
     * Para sesiones cerradas se usan los valores persistidos por
     * closeSession() (expected_amount/counted_amount/difference), que
     * coinciden con el calculo en vivo porque cash_movements es inmutable.
     *
     * @return array{opening_amount: float, cash_affecting_delta: float, expected_amount: float, counted_amount: float|null, difference: float|null}
     */
    private function cashSummary(CashSession $session): array
    {
        $opening = (float) $session->opening_amount;
        $delta = $this->cash->cashAffectingDelta($session->id);
        $isClosed = $session->status !== CashSession::STATUS_OPEN;

        return [
            'opening_amount' => round($opening, 2),
            'cash_affecting_delta' => round($delta, 2),
            'expected_amount' => $isClosed && $session->expected_amount !== null
                ? round((float) $session->expected_amount, 2)
                : round($opening + $delta, 2),
            'counted_amount' => $session->counted_amount !== null
                ? round((float) $session->counted_amount, 2) : null,
            'difference' => $session->difference !== null
                ? round((float) $session->difference, 2) : null,
        ];
    }

    /**
     * Diferencias de caja de las sesiones CERRADAS en un rango.
     *
     * No recalcula nada: expected_amount, counted_amount y difference ya los
     * persiste CashService::closeSession(). Aqui solo se agregan por rango.
     *
     * Filtra status=closed a proposito: las sesiones open no tienen arqueo y
     * las voided no contabilizan (ver migracion 000018).
     *
     * El rango se aplica sobre closed_at, no opened_at: una sesion que abre el
     * 30 y cierra el 1 pertenece al arqueo del dia 1.
     */
    public function differencesByRange(string $from, string $to, ?string $branchUuid = null, ?int $limit = null): array
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->endOfDay();

        $branch = $branchUuid !== null
            ? Branch::query()->where('uuid', $branchUuid)->first()
            : null;

        $query = CashSession::query()
            ->join('cash_registers', 'cash_registers.id', '=', 'cash_sessions.cash_register_id')
            ->leftJoin('users', 'users.id', '=', 'cash_sessions.closed_by')
            ->where('cash_sessions.status', CashSession::STATUS_CLOSED)
            ->whereNotNull('cash_sessions.difference')
            ->whereBetween('cash_sessions.closed_at', [$start, $end]);

        if ($branch !== null) {
            $query->where('cash_sessions.branch_id', $branch->id);
        }

        $query->orderBy('cash_sessions.closed_at');

        if ($limit !== null) {
            $query->limit($limit);
        }

        $rows = $query->get([
            'cash_sessions.uuid as session_uuid',
            'cash_sessions.closed_at',
            'cash_sessions.expected_amount',
            'cash_sessions.counted_amount',
            'cash_sessions.difference',
            'cash_registers.code as register_code',
            'users.name as closed_by_name',
        ])->map(fn ($r) => [
            'session_uuid' => (string) $r->session_uuid,
            'closed_at' => CarbonImmutable::parse($r->closed_at)->toDateTimeString(),
            'register_code' => (string) $r->register_code,
            'closed_by_name' => $r->closed_by_name !== null ? (string) $r->closed_by_name : null,
            'expected_amount' => round((float) $r->expected_amount, 2),
            'counted_amount' => round((float) $r->counted_amount, 2),
            'difference' => round((float) $r->difference, 2),
        ])->all();

        $diffs = array_column($rows, 'difference');

        return [
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'branch' => $branch !== null ? [
                'uuid' => $branch->uuid,
                'code' => $branch->code,
                'name' => $branch->name,
            ] : null,
            'totals' => [
                'sessions_count' => count($rows),
                'net_difference' => round(array_sum($diffs), 2),
                'shortage_amount' => round(array_sum(array_filter($diffs, fn ($d) => $d < 0)), 2),
                'overage_amount' => round(array_sum(array_filter($diffs, fn ($d) => $d > 0)), 2),
                'balanced_count' => count(array_filter($diffs, fn ($d) => abs($d) < 0.01)),
            ],
            'rows' => $rows,
        ];
    }
}
