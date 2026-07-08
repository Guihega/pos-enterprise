<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Exceptions\ExpiredBatchException;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Models\Batch;
use App\Domain\Inventory\Models\InventoryMovement;
use App\Domain\Inventory\Models\Stock;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Operaciones atómicas sobre stock.
 *
 * Todos los métodos:
 *   1. Abren transacción.
 *   2. Aplican lockForUpdate sobre el registro de Stock (PostgreSQL row-lock).
 *   3. Modifican stock + insertan inventory_movement.
 *   4. Commit (todo o nada).
 *
 * El lockForUpdate previene race conditions: si dos procesos intentan
 * descontar stock al mismo tiempo, uno espera al otro. PostgreSQL libera
 * el lock al commit.
 */
final class InventoryService
{
    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Registra una entrada (compra, devolución de cliente, ajuste positivo).
     */
    public function recordEntry(
        Product $product,
        Warehouse $warehouse,
        float $quantity,
        float $unitCost = 0,
        string $type = InventoryMovement::TYPE_ENTRY,
        ?string $reason = null,
        ?string $reference = null,
        ?Model $source = null,
        ?int $userId = null,
        ?string $transferId = null,
        ?array $batch = null,
    ): InventoryMovement {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Quantity must be positive for entries');
        }

        // RN-034 (estricta): capturar caducidad exige tracks_lots=true.
        if (($batch['expiration_date'] ?? null) !== null && ! $product->tracks_lots) {
            throw new \InvalidArgumentException(
                'El producto no maneja lotes (tracks_lots=false); no se puede capturar caducidad.'
            );
        }

        return DB::transaction(function () use (
            $product, $warehouse, $quantity, $unitCost, $type,
            $reason, $reference, $source, $userId, $transferId, $batch
        ) {
            $stock = $this->lockOrCreateStock($product, $warehouse);

            // Costo promedio ponderado:
            // new_avg = (current_qty * current_avg + entry_qty * entry_cost) / (current_qty + entry_qty)
            $currentQty = (float) $stock->quantity_on_hand;
            $currentAvg = (float) $stock->average_cost;
            $totalCost = $unitCost * $quantity;

            $newAvg = ($currentQty + $quantity) > 0
                ? round(($currentQty * $currentAvg + $totalCost) / ($currentQty + $quantity), 4)
                : $unitCost;

            $stock->quantity_on_hand = $currentQty + $quantity;
            $stock->average_cost = $newAvg;
            $stock->last_movement_at = now();
            $stock->save();

            if ($product->tracks_lots && $batch !== null) {
                $this->createBatchForEntry($product, $warehouse, $quantity, $unitCost, $batch);
            }

            return $this->writeMovement(
                product: $product,
                warehouse: $warehouse,
                type: $type,
                quantityDelta: $quantity,
                quantityAfter: (float) $stock->quantity_on_hand,
                unitCost: $unitCost,
                totalCost: $totalCost,
                averageCostAfter: $newAvg,
                reason: $reason,
                reference: $reference,
                source: $source,
                userId: $userId,
                transferId: $transferId,
            );
        });
    }

    /**
     * Registra una salida (venta, devolución a proveedor, merma).
     */
    public function recordExit(
        Product $product,
        Warehouse $warehouse,
        float $quantity,
        string $type = InventoryMovement::TYPE_EXIT,
        ?string $reason = null,
        ?string $reference = null,
        ?Model $source = null,
        ?int $userId = null,
        ?string $transferId = null,
        ?array &$batchConsumption = null,
    ): InventoryMovement {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Quantity must be positive for exits');
        }

        $crossedLowStock = false;

        $movement = DB::transaction(function () use (
            $product, $warehouse, $quantity, $type,
            $reason, $reference, $source, $userId, $transferId,
            &$crossedLowStock, &$batchConsumption
        ) {
            $stock = $this->lockOrCreateStock($product, $warehouse);

            $currentQty = (float) $stock->quantity_on_hand;
            if ($currentQty < $quantity) {
                throw InsufficientStockException::forProduct(
                    $product->id, $warehouse->id, $quantity, $currentQty
                );
            }

            $unitCost = (float) $stock->average_cost;
            $totalCost = $unitCost * $quantity;

            $stock->quantity_on_hand = $currentQty - $quantity;
            $stock->last_movement_at = now();
            $stock->save();

            if ($product->tracks_lots) {
                // EX-041: solo la VENTA bloquea lote vencido; merma/ajuste
                // (TYPE_ADJUSTMENT) puede sacar producto caducado (EX-049).
                $batchConsumption = $this->consumeBatchesFefo(
                    $product,
                    $warehouse,
                    $quantity,
                    blockExpired: $type === InventoryMovement::TYPE_EXIT,
                );
            }

            // RN-058 / RN-190: una salida que deja el stock <= min genera alerta
            // de reabastecimiento a ALMACEN/GERENTE de la sucursal. Se excluye
            // TYPE_TRANSFER_OUT porque el envio a transito no es reabastecimiento
            // (el inventario global no baja, solo cambia de sucursal); el maestro
            // trata transfer_out como categoria propia. El disparo se captura aqui
            // (stock ya descontado) y se despacha tras el commit.
            $crossedLowStock = $type !== InventoryMovement::TYPE_TRANSFER_OUT
                && $stock->isLowStock();

            return $this->writeMovement(
                product: $product,
                warehouse: $warehouse,
                type: $type,
                quantityDelta: -$quantity,
                quantityAfter: (float) $stock->quantity_on_hand,
                unitCost: $unitCost,
                totalCost: $totalCost,
                averageCostAfter: (float) $stock->average_cost,
                reason: $reason,
                reference: $reference,
                source: $source,
                userId: $userId,
                transferId: $transferId,
            );
        });

        if ($crossedLowStock) {
            $this->notifyLowStock($product, $warehouse, (float) $movement->quantity_after);
        }

        return $movement;
    }

    /**
     * Ajuste manual: el delta puede ser positivo o negativo. Reason obligatorio.
     */
    public function adjust(
        Product $product,
        Warehouse $warehouse,
        float $delta,
        string $reason,
        ?int $userId = null,
    ): InventoryMovement {
        if ($delta === 0.0) {
            throw new \InvalidArgumentException('Adjustment delta cannot be zero');
        }
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('Reason is required for adjustments');
        }

        return $delta > 0
            ? $this->recordEntry(
                product: $product, warehouse: $warehouse, quantity: $delta,
                unitCost: 0, type: InventoryMovement::TYPE_ADJUSTMENT,
                reason: $reason, userId: $userId,
            )
            : $this->recordExit(
                product: $product, warehouse: $warehouse, quantity: abs($delta),
                type: InventoryMovement::TYPE_ADJUSTMENT,
                reason: $reason, userId: $userId,
            );
    }

    /**
     * Transferencia entre warehouses: produce DOS movimientos linked por transfer_id.
     *
     * @return array{out: InventoryMovement, in: InventoryMovement, transfer_id: string}
     */
    public function transfer(
        Product $product,
        Warehouse $from,
        Warehouse $to,
        float $quantity,
        ?string $reason = null,
        ?int $userId = null,
    ): array {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Transfer quantity must be positive');
        }
        if ($from->id === $to->id) {
            throw new \InvalidArgumentException('Cannot transfer to the same warehouse');
        }
        if ($from->company_id !== $to->company_id) {
            throw new \InvalidArgumentException('Cannot transfer across tenants');
        }

        return DB::transaction(function () use ($product, $from, $to, $quantity, $reason, $userId) {
            $transferId = (string) Str::uuid();

            $out = $this->recordExit(
                product: $product, warehouse: $from, quantity: $quantity,
                type: InventoryMovement::TYPE_TRANSFER_OUT,
                reason: $reason, userId: $userId, transferId: $transferId,
            );

            $in = $this->recordEntry(
                product: $product, warehouse: $to, quantity: $quantity,
                unitCost: (float) $out->unit_cost,
                type: InventoryMovement::TYPE_TRANSFER_IN,
                reason: $reason, userId: $userId, transferId: $transferId,
            );

            return ['out' => $out, 'in' => $in, 'transfer_id' => $transferId];
        });
    }

    /**
     * Consume lotes FEFO (RN-045): caducidad mas proxima primero, sin
     * caducidad al final. Con blockExpired (venta) el primer lote vencido
     * en la cola detiene la operacion (EX-041); sin el (merma/ajuste) los
     * vencidos se consumen igual (EX-049).
     *
     * Lock pesimista por lote: el orden FEFO es determinista, lo que evita
     * interbloqueos entre salidas concurrentes del mismo producto.
     *
     * @return list<array{batch_id: int, quantity: float, unit_cost: float}>
     */
    private function consumeBatchesFefo(
        Product $product,
        Warehouse $warehouse,
        float $quantity,
        bool $blockExpired,
    ): array {
        $remaining = $quantity;
        $consumed = [];

        $batches = Batch::query()
            ->where('product_id', $product->id)
            ->where('branch_id', $warehouse->branch_id)
            ->available()
            ->fefo()
            ->lockForUpdate()
            ->get();

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }

            if ($blockExpired && $batch->isExpired()) {
                throw ExpiredBatchException::forProduct(
                    $product->id,
                    $batch->expiration_date->toDateString(),
                );
            }

            $take = min($remaining, (float) $batch->quantity);
            $batch->quantity = (float) $batch->quantity - $take;
            $batch->save();

            $consumed[] = [
                'batch_id' => $batch->id,
                'quantity' => $take,
                'unit_cost' => (float) $batch->cost,
            ];

            $remaining = round($remaining - $take, 3);
        }

        // Si los lotes no cubren la salida (stock previo a lotes, o
        // desalineacion), el excedente sale sin lote: el stock global ya
        // valido la existencia y es la fuente de verdad (EX-042 vigila
        // desalineaciones del otro sentido).
        return $consumed;
    }

    /**
     * Crea el lote de una entrada (doc maestro product_batches, RN-046).
     * lot_number y expiration_date opcionales; quantity arranca igual a
     * received_quantity y se consume con FEFO (RN-045) en las salidas.
     *
     * @param  array{lot_number?: string|null, expiration_date?: string|null, notes?: string|null}  $batch
     */
    private function createBatchForEntry(
        Product $product,
        Warehouse $warehouse,
        float $quantity,
        float $unitCost,
        array $batch,
    ): Batch {
        return Batch::query()->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => TenantContext::id(),
            'product_id' => $product->id,
            'branch_id' => $warehouse->branch_id,
            'warehouse_id' => $warehouse->id,
            'lot_number' => $batch['lot_number'] ?? null,
            'expiration_date' => $batch['expiration_date'] ?? null,
            'received_date' => now()->toDateString(),
            'received_quantity' => $quantity,
            'quantity' => $quantity,
            'cost' => $unitCost,
            'notes' => $batch['notes'] ?? null,
        ]);
    }

    /**
     * Adquiere lock pesimista sobre el stock (lo crea si no existe).
     */
    private function lockOrCreateStock(Product $product, Warehouse $warehouse): Stock
    {
        // Garantizar que el row existe (firstOrCreate sin lock).
        Stock::firstOrCreate(
            ['product_id' => $product->id, 'warehouse_id' => $warehouse->id],
            [
                'company_id' => TenantContext::id(),
                'quantity_on_hand' => 0,
                'quantity_reserved' => 0,
                'average_cost' => 0,
            ]
        );

        // Adquirir el lock pesimista.
        return Stock::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function writeMovement(
        Product $product,
        Warehouse $warehouse,
        string $type,
        float $quantityDelta,
        float $quantityAfter,
        float $unitCost,
        float $totalCost,
        float $averageCostAfter,
        ?string $reason,
        ?string $reference,
        ?Model $source,
        ?int $userId,
        ?string $transferId,
    ): InventoryMovement {
        return InventoryMovement::create([
            'uuid' => (string) Str::uuid(),
            'company_id' => TenantContext::id(),
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'branch_id' => $warehouse->branch_id,
            'type' => $type,
            'source_type' => $source !== null ? $source::class : null,
            'source_id' => $source?->getKey(),
            'transfer_id' => $transferId,
            'quantity_delta' => $quantityDelta,
            'quantity_after' => $quantityAfter,
            'unit_cost' => $unitCost,
            'total_cost' => $totalCost,
            'average_cost_after' => $averageCostAfter,
            'reason' => $reason,
            'reference' => $reference,
            'user_id' => $userId,
            'movement_at' => now(),
        ]);
    }

    /**
     * RN-190: notifica stock bajo a usuarios ALMACEN/GERENTE asignados a la
     * sucursal del almacen. Efecto secundario fuera de la transaccion de
     * inventario: si la notificacion fallara no debe revertir el movimiento.
     */
    private function notifyLowStock(Product $product, Warehouse $warehouse, float $onHand): void
    {
        $branch = $warehouse->branch;

        if ($branch === null) {
            return;
        }

        $recipients = $this->notifications->warehouseAndManagerUsersForBranch($branch);

        foreach ($recipients as $recipient) {
            $this->notifications->notify(
                recipient: $recipient,
                type: 'stock.low',
                data: [
                    'product_id' => $product->id,
                    'product_uuid' => $product->uuid,
                    'warehouse_id' => $warehouse->id,
                    'branch_id' => $branch->id,
                    'quantity_on_hand' => $onHand,
                ],
                severity: Notification::SEVERITY_WARNING,
            );
        }
    }
}
