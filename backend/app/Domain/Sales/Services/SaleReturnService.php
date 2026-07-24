<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Cash\Models\CashMovement;
use App\Domain\Cash\Models\CashSession;
use App\Domain\Cash\Services\CashService;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\InventoryMovement;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Sales\Exceptions\SaleNotReturnableException;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleItem;
use App\Domain\Sales\Models\SalePayment;
use App\Domain\Sales\Models\SaleReturn;
use App\Domain\Sales\Models\SaleReturnItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Devoluciones basicas (CU-CAJ-010). Reverso parcial o total de una
 * venta completada, por folio (RN-086 estricta: siempre con
 * referencia a la venta).
 *
 * Decisiones documentadas:
 * - RN-085 configurable via Company.setting('returns.window_days'),
 *   default 30; ventana desde completed_at ?? created_at.
 * - Reembolso en efectivo limitado al componente cash de la venta
 *   (proporcional al monto devuelto, tope el total cash pagado menos
 *   lo ya reembolsado); tarjeta/credito NO se reversan aqui (proceso
 *   manual, decision heredada de cancel).
 * - La venta pasa a STATUS_REFUNDED solo con devolucion total
 *   (cantidades acumuladas == vendidas); parcial conserva completed.
 * - Autorizacion supervisor (RN-083/CU paso 7) = permiso SALE_REFUND
 *   en el controller, mismo patron que SALE_VOID.
 */
final class SaleReturnService
{
    private const DEFAULT_WINDOW_DAYS = 30;

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly CashService $cash,
        private readonly ActivityLogger $logger,
    ) {}

    /**
     * @param  array<int, array{sale_item_uuid: string, quantity: float}>  $items
     */
    public function create(Sale $sale, array $items, User $user, string $reason): SaleReturn
    {
        $return = DB::transaction(function () use ($sale, $items, $user, $reason): SaleReturn {
            /** @var Sale $locked */
            $locked = Sale::query()->where('id', $sale->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isCompleted()) {
                throw SaleNotReturnableException::forStatus($locked->status);
            }

            $windowDays = (int) $locked->company->setting('returns.window_days', self::DEFAULT_WINDOW_DAYS);
            $soldAt = $locked->completed_at ?? $locked->created_at;
            if ($soldAt->copy()->addDays($windowDays)->isPast()) {
                throw SaleNotReturnableException::windowExpired($windowDays);
            }

            /** @var CashSession $session */
            $session = CashSession::query()
                ->where('id', $locked->cash_session_id)
                ->lockForUpdate()
                ->firstOrFail();

            $saleItems = $locked->items->keyBy('uuid');
            $alreadyReturned = SaleReturnItem::query()
                ->whereIn('sale_item_id', $locked->items->pluck('id'))
                ->selectRaw('sale_item_id, SUM(quantity) AS qty')
                ->groupBy('sale_item_id')
                ->pluck('qty', 'sale_item_id');

            $totalAmount = 0.0;
            $lines = [];
            foreach ($items as $line) {
                /** @var SaleItem|null $saleItem */
                $saleItem = $saleItems->get($line['sale_item_uuid']);
                if ($saleItem === null) {
                    throw SaleNotReturnableException::quantityExceeded($line['sale_item_uuid']);
                }
                $available = (float) $saleItem->quantity - (float) ($alreadyReturned[$saleItem->id] ?? 0);
                $qty = (float) $line['quantity'];
                if ($qty <= 0 || $qty > $available) {
                    throw SaleNotReturnableException::quantityExceeded($saleItem->product->uuid);
                }
                // Monto proporcional al precio real pagado del renglon.
                $amount = round(((float) $saleItem->total / (float) $saleItem->quantity) * $qty, 4);
                $totalAmount += $amount;
                $lines[] = ['saleItem' => $saleItem, 'qty' => $qty, 'amount' => $amount];
            }

            // Reembolso cash: proporcional, tope = cash pagado - cash ya reembolsado.
            $cashPaid = (float) $locked->payments
                ->where('method', SalePayment::METHOD_CASH)
                ->sum('amount');
            $cashAlready = (float) SaleReturn::query()
                ->where('sale_id', $locked->id)
                ->sum('cash_refunded');
            $cashRefund = min($totalAmount, max(0.0, $cashPaid - $cashAlready));
            if ($cashRefund > 0 && ! $session->isOpen()) {
                throw SaleNotReturnableException::sessionClosed();
            }

            $return = SaleReturn::query()->create([
                'uuid' => (string) Str::uuid(),
                'sale_id' => $locked->id,
                'branch_id' => $locked->branch_id,
                'cash_session_id' => $session->id,
                'user_id' => $user->id,
                'total_amount' => $totalAmount,
                'cash_refunded' => $cashRefund,
                'reason' => $reason,
            ]);

            $warehouse = Warehouse::find($locked->warehouse_id);
            foreach ($lines as $l) {
                SaleReturnItem::query()->create([
                    'uuid' => (string) Str::uuid(),
                    'sale_return_id' => $return->id,
                    'sale_item_id' => $l['saleItem']->id,
                    'product_id' => $l['saleItem']->product_id,
                    'quantity' => $l['qty'],
                    'amount' => $l['amount'],
                ]);
                if ($l['saleItem']->track_inventory) {
                    $this->inventory->recordEntry(
                        product: $l['saleItem']->product,
                        warehouse: $warehouse,
                        quantity: $l['qty'],
                        unitCost: (float) $l['saleItem']->unit_cost,
                        type: InventoryMovement::TYPE_RETURN_CUSTOMER,
                        reason: "Devolucion venta {$locked->number}: {$reason}",
                        reference: $locked->number,
                        source: $return,
                        userId: $user->id,
                    );
                }
            }

            if ($cashRefund > 0) {
                $this->cash->addMovement(
                    session: $session,
                    user: $user,
                    type: CashMovement::TYPE_REFUND_CASH,
                    amount: $cashRefund,
                    reason: "Devolucion venta {$locked->number}: {$reason}",
                    reference: $locked->number,
                    source: $return,
                );
            }

            // Devolucion total => la venta pasa a refunded.
            $returnedNow = SaleReturnItem::query()
                ->whereIn('sale_item_id', $locked->items->pluck('id'))
                ->sum('quantity');
            if ((float) $returnedNow >= (float) $locked->items->sum('quantity')) {
                $locked->update(['status' => Sale::STATUS_REFUNDED]);
            }

            return $return;
        });

        $this->logger->log(
            logName: 'sales',
            event: 'sale.returned',
            description: 'Devolucion de venta registrada',
            subject: $return,
            properties: [
                'sale_number' => $sale->number,
                'total_amount' => (string) $return->total_amount,
                'cash_refunded' => (string) $return->cash_refunded,
            ],
        );

        return $return;
    }
}
