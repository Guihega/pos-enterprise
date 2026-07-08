<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Exceptions\InvalidTransferTransitionException;
use App\Domain\Inventory\Models\InventoryMovement;
use App\Domain\Inventory\Models\Transfer;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Orquesta la maquina de estados de transferencias inter-sucursal
 * (doc maestro 46.4 y 14.5).
 *
 * Reutiliza InventoryService para todos los movimientos de stock, de modo
 * que el kardex (inventory_movements) queda consistente: el envio genera
 * transfer_out en el almacen origen y la recepcion transfer_in en destino,
 * ligados por transfer_id = Transfer->uuid y source = el Transfer.
 *
 * El stock NO se mueve al crear (draft); baja al enviar (sent) y entra al
 * recibir (received). Esto modela el traslado fisico real que puede tardar
 * dias.
 */
final class TransferService
{
    public function __construct(
        private readonly InventoryService $inventory,
    ) {}

    /**
     * Crea una transferencia en estado draft con sus lineas.
     *
     * @param  list<array{product: Product, quantity: float, unit_cost?: float, notes?: string|null}>  $lines
     */
    public function create(
        Branch $fromBranch,
        Branch $toBranch,
        array $lines,
        ?Warehouse $fromWarehouse = null,
        ?Warehouse $toWarehouse = null,
        ?string $transportMethod = null,
        ?string $transportReference = null,
        ?string $notes = null,
    ): Transfer {
        if ($fromBranch->id === $toBranch->id) {
            throw new \InvalidArgumentException('Origen y destino deben ser sucursales distintas.');
        }
        if ($lines === []) {
            throw new \InvalidArgumentException('La transferencia requiere al menos una linea.');
        }

        return DB::transaction(function () use (
            $fromBranch, $toBranch, $lines, $fromWarehouse, $toWarehouse,
            $transportMethod, $transportReference, $notes
        ): Transfer {
            $transfer = Transfer::create([
                'uuid' => (string) Str::uuid(),
                'folio' => $this->nextFolio(),
                'from_branch_id' => $fromBranch->id,
                'to_branch_id' => $toBranch->id,
                'from_warehouse_id' => $fromWarehouse?->id,
                'to_warehouse_id' => $toWarehouse?->id,
                'status' => Transfer::STATUS_DRAFT,
                'transport_method' => $transportMethod,
                'transport_reference' => $transportReference,
                'notes' => $notes,
                'total_cost' => 0,
            ]);

            foreach ($lines as $line) {
                $transfer->items()->create([
                    'company_id' => TenantContext::id(),
                    'product_id' => $line['product']->id,
                    'quantity_sent' => $line['quantity'],
                    'quantity_received' => null,
                    'unit_cost' => $line['unit_cost'] ?? 0,
                    'notes' => $line['notes'] ?? null,
                ]);
            }

            return $transfer->fresh(['items']);
        });
    }

    /**
     * draft -> sent: descuenta stock del almacen origen por cada linea.
     */
    public function send(Transfer $transfer, ?int $userId = null): Transfer
    {
        $this->assertTransition($transfer, Transfer::STATUS_SENT);

        $fromWarehouse = $this->resolveFromWarehouse($transfer);

        return DB::transaction(function () use ($transfer, $fromWarehouse, $userId): Transfer {
            $totalCost = 0.0;

            foreach ($transfer->items as $item) {
                $product = Product::query()->findOrFail($item->product_id);

                $movement = $this->inventory->recordExit(
                    product: $product,
                    warehouse: $fromWarehouse,
                    quantity: (float) $item->quantity_sent,
                    type: InventoryMovement::TYPE_TRANSFER_OUT,
                    reason: 'Transferencia '.$transfer->folio,
                    reference: $transfer->folio,
                    source: $transfer,
                    userId: $userId,
                    transferId: $transfer->uuid,
                );

                // Fijar el costo unitario de la linea al costo real de salida.
                $item->unit_cost = (float) $movement->unit_cost;
                $item->save();

                $totalCost += (float) $movement->total_cost;
            }

            $transfer->update([
                'status' => Transfer::STATUS_SENT,
                'sent_by_user_id' => $userId,
                'sent_at' => now(),
                'from_warehouse_id' => $fromWarehouse->id,
                'total_cost' => $totalCost,
            ]);

            return $transfer->fresh(['items']);
        });
    }

    /**
     * sent -> received: ingresa stock al almacen destino segun cantidad
     * recibida. Si recibida < enviada, la diferencia es merma (transfer_loss,
     * RN-049): no se ingresa al destino y queda registrada en quantity_received.
     *
     * @param  array<int, float>  $receivedByItemId  map item_id => cantidad recibida.
     *                                               Si no se provee una linea, se
     *                                               asume recepcion completa.
     */
    public function receive(Transfer $transfer, array $receivedByItemId = [], ?int $userId = null): Transfer
    {
        $this->assertTransition($transfer, Transfer::STATUS_RECEIVED);

        $toWarehouse = $this->resolveToWarehouse($transfer);

        return DB::transaction(function () use ($transfer, $toWarehouse, $receivedByItemId, $userId): Transfer {
            foreach ($transfer->items as $item) {
                $sent = (float) $item->quantity_sent;
                $received = array_key_exists($item->id, $receivedByItemId)
                    ? (float) $receivedByItemId[$item->id]
                    : $sent;

                if ($received < 0) {
                    throw new \InvalidArgumentException('La cantidad recibida no puede ser negativa.');
                }
                if ($received > $sent) {
                    throw new \InvalidArgumentException('La cantidad recibida no puede exceder la enviada.');
                }

                $product = Product::query()->findOrFail($item->product_id);

                // Ingresa al destino TODO lo despachado: el kardex refleja el
                // envio real. La merma se descuenta despues como ajuste
                // trazable (transfer_loss), nunca como una resta implicita.
                $this->inventory->recordEntry(
                    product: $product,
                    warehouse: $toWarehouse,
                    quantity: $sent,
                    unitCost: (float) $item->unit_cost,
                    type: InventoryMovement::TYPE_TRANSFER_IN,
                    reason: 'Recepcion transferencia '.$transfer->folio,
                    reference: $transfer->folio,
                    source: $transfer,
                    userId: $userId,
                    transferId: $transfer->uuid,
                );

                // RN-049: discrepancia (sent - received) genera ajuste automatico
                // con motivo transfer_loss contra el almacen destino. Queda como
                // movimiento de kardex auditable, no como una cantidad perdida.
                $loss = $sent - $received;
                if ($loss > 0) {
                    $this->inventory->adjust(
                        product: $product,
                        warehouse: $toWarehouse,
                        delta: -$loss,
                        reason: 'transfer_loss',
                        userId: $userId,
                    );
                }
                $item->quantity_received = $received;
                $item->save();
            }

            $transfer->update([
                'status' => Transfer::STATUS_RECEIVED,
                'received_by_user_id' => $userId,
                'received_at' => now(),
                'to_warehouse_id' => $toWarehouse->id,
            ]);

            return $transfer->fresh(['items']);
        });
    }

    /**
     * sent -> returned_to_origin: la mercancia se rechaza y vuelve al origen.
     * Reingresa el stock completo al almacen origen.
     */
    public function returnToOrigin(Transfer $transfer, ?int $userId = null): Transfer
    {
        $this->assertTransition($transfer, Transfer::STATUS_RETURNED_TO_ORIGIN);

        $fromWarehouse = $this->resolveFromWarehouse($transfer);

        return DB::transaction(function () use ($transfer, $fromWarehouse, $userId): Transfer {
            foreach ($transfer->items as $item) {
                $product = Product::query()->findOrFail($item->product_id);
                $this->inventory->recordEntry(
                    product: $product,
                    warehouse: $fromWarehouse,
                    quantity: (float) $item->quantity_sent,
                    unitCost: (float) $item->unit_cost,
                    type: InventoryMovement::TYPE_TRANSFER_IN,
                    reason: 'Devolucion a origen transferencia '.$transfer->folio,
                    reference: $transfer->folio,
                    source: $transfer,
                    userId: $userId,
                    transferId: $transfer->uuid,
                );
            }

            $transfer->update([
                'status' => Transfer::STATUS_RETURNED_TO_ORIGIN,
            ]);

            return $transfer->fresh(['items']);
        });
    }

    /**
     * draft|returned_to_origin -> cancelled. Desde draft no hay stock que
     * revertir; desde returned_to_origin el stock ya volvio al origen.
     */
    public function cancel(Transfer $transfer, ?string $reason = null, ?int $userId = null): Transfer
    {
        $this->assertTransition($transfer, Transfer::STATUS_CANCELLED);

        $transfer->update([
            'status' => Transfer::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancelled_by' => $userId,
            'cancellation_reason' => $reason,
        ]);

        return $transfer->fresh(['items']);
    }

    private function assertTransition(Transfer $transfer, string $target): void
    {
        if (! $transfer->canTransitionTo($target)) {
            throw InvalidTransferTransitionException::between($transfer->status, $target);
        }
    }

    private function resolveFromWarehouse(Transfer $transfer): Warehouse
    {
        if ($transfer->from_warehouse_id !== null) {
            return Warehouse::query()->findOrFail($transfer->from_warehouse_id);
        }

        return $this->defaultWarehouseForBranch($transfer->from_branch_id);
    }

    private function resolveToWarehouse(Transfer $transfer): Warehouse
    {
        if ($transfer->to_warehouse_id !== null) {
            return Warehouse::query()->findOrFail($transfer->to_warehouse_id);
        }

        return $this->defaultWarehouseForBranch($transfer->to_branch_id);
    }

    private function defaultWarehouseForBranch(int $branchId): Warehouse
    {
        $warehouse = Warehouse::query()
            ->where('branch_id', $branchId)
            ->where('is_default', true)
            ->first()
            ?? Warehouse::query()->where('branch_id', $branchId)->first();

        if ($warehouse === null) {
            throw new \RuntimeException('La sucursal no tiene almacen configurado.');
        }

        return $warehouse;
    }

    /**
     * Folio simple por tenant: TR-{YYYYMMDD}-{correlativo del dia}.
     * Encapsulado para sustituir luego por un generador centralizado si se
     * requiere (paralelo a SaleNumberGenerator).
     */
    private function nextFolio(): string
    {
        $prefix = 'TR-'.now()->format('Ymd').'-';
        $count = Transfer::query()
            ->where('folio', 'like', $prefix.'%')
            ->count();

        return $prefix.str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT);
    }
}
