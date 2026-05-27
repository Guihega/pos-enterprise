<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Models\InventoryMovement;
use App\Domain\Inventory\Models\Stock;
use App\Domain\Inventory\Models\Warehouse;
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
    ): InventoryMovement {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Quantity must be positive for entries');
        }

        return DB::transaction(function () use (
            $product, $warehouse, $quantity, $unitCost, $type,
            $reason, $reference, $source, $userId, $transferId
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
    ): InventoryMovement {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Quantity must be positive for exits');
        }

        return DB::transaction(function () use (
            $product, $warehouse, $quantity, $type,
            $reason, $reference, $source, $userId, $transferId
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
}
