<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Batch;
use App\Domain\Inventory\Models\InventoryMovement;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Purchasing\Exceptions\PurchaseOrderTransitionException;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\PurchaseOrderItem;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Ordenes de compra: creacion, edicion en draft y transiciones de estado.
 *
 * Incluye la recepcion (/receive), que acumula sobre quantity_received y
 * mueve stock via InventoryService. La OC pasa a received solo cuando todas
 * las lineas estan completas.
 *
 * Folio: consecutivo por tenant calculado dentro de la transaccion. No usa
 * FolioRangeService, que reserva rangos por dispositivo para operar offline
 * y es especifico de tickets de venta; una OC se crea online desde
 * backoffice. El unique (company_id, folio) es la red de seguridad.
 *
 * Impuestos: tax_rate se toma de product->tax->rate, que es FRACCION
 * (0.16 = 16%), igual que en SaleTotalsCalculator. El unit_cost es siempre
 * neto, asi que no se replica la rama is_inclusive de ventas.
 */
class PurchaseOrderService
{
    public function __construct(
        private readonly InventoryService $inventory,
    ) {}

    /**
     * @param  array<int, array{product_uuid: string, quantity: float, unit_cost: float}>  $items
     */
    public function create(
        string $supplierUuid,
        string $branchUuid,
        array $items,
        User $user,
        ?string $expectedDate = null,
        ?string $notes = null,
    ): PurchaseOrder {
        if ($items === []) {
            throw new InvalidArgumentException('Una orden de compra necesita al menos una linea.');
        }

        return DB::transaction(function () use ($supplierUuid, $branchUuid, $items, $user, $expectedDate, $notes) {
            $supplier = Supplier::query()->where('uuid', $supplierUuid)->firstOrFail();
            $branch = Branch::query()->where('uuid', $branchUuid)->firstOrFail();

            $productUuids = array_map(fn (array $i): string => $i['product_uuid'], $items);
            $products = Product::query()
                ->whereIn('uuid', $productUuids)
                ->with('tax')
                ->get()
                ->keyBy('uuid');

            $lines = [];
            $subtotal = 0.0;
            $taxTotal = 0.0;

            foreach ($items as $item) {
                $product = $products->get($item['product_uuid']);
                if ($product === null) {
                    throw new InvalidArgumentException(
                        sprintf('Producto %s no encontrado en este tenant.', $item['product_uuid'])
                    );
                }

                $calc = $this->calculateLine(
                    (float) $item['quantity'],
                    (float) $item['unit_cost'],
                    $product->tax !== null ? (float) $product->tax->rate : 0.0,
                );

                $lines[] = ['product_id' => $product->id] + $calc;
                $subtotal += $calc['subtotal'];
                $taxTotal += $calc['tax_amount'];
            }

            $order = PurchaseOrder::create([
                'uuid' => (string) Str::uuid(),
                'company_id' => TenantContext::id(),
                'branch_id' => $branch->id,
                'supplier_id' => $supplier->id,
                'folio' => $this->nextFolio(),
                'status' => PurchaseOrder::STATUS_DRAFT,
                'requested_by' => $user->id,
                'expected_date' => $expectedDate,
                'notes' => $notes,
                'subtotal' => round($subtotal, 2),
                'tax_total' => round($taxTotal, 2),
                'total' => round($subtotal + $taxTotal, 2),
            ]);

            foreach ($lines as $line) {
                PurchaseOrderItem::create([
                    'company_id' => TenantContext::id(),
                    'purchase_order_id' => $order->id,
                ] + $line);
            }

            return $order->fresh(['items']);
        });
    }

    /**
     * Actualiza una orden en draft (maestro 29.7: Actualizar si draft).
     *
     * PATCH parcial. Si $items es null las lineas no se tocan y los
     * totales quedan intactos; si viene, se reemplazan TODAS las lineas
     * y se recalculan subtotal, tax_total y total.
     *
     * El reemplazo recalcula con la tasa de impuesto VIGENTE del producto,
     * igual que create(): un draft no es documento fiscal y refleja lo que
     * costaria hoy. No se congela la tasa original.
     *
     * supplier_id y branch_id no son modificables por decision de alcance.
     *
     * @param  array<int, array{product_uuid: string, quantity: float, unit_cost: float}>|null  $items
     */
    public function update(
        PurchaseOrder $order,
        ?array $items = null,
        ?string $expectedDate = null,
        ?string $notes = null,
        bool $touchExpectedDate = false,
        bool $touchNotes = false,
    ): PurchaseOrder {
        if ($items !== null && $items === []) {
            throw new InvalidArgumentException('Una orden de compra necesita al menos una linea.');
        }

        return DB::transaction(function () use ($order, $items, $expectedDate, $notes, $touchExpectedDate, $touchNotes) {
            /** @var PurchaseOrder $locked */
            $locked = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PurchaseOrder::STATUS_DRAFT) {
                throw PurchaseOrderTransitionException::forStatus($locked->status, 'actualizar');
            }

            $cambios = [];

            if ($touchExpectedDate) {
                $cambios['expected_date'] = $expectedDate;
            }

            if ($touchNotes) {
                $cambios['notes'] = $notes;
            }

            if ($items !== null) {
                $productUuids = array_map(fn (array $i): string => $i['product_uuid'], $items);

                $products = Product::query()
                    ->whereIn('uuid', $productUuids)
                    ->with('tax')
                    ->get()
                    ->keyBy('uuid');

                $lines = [];
                $subtotal = 0.0;
                $taxTotal = 0.0;

                foreach ($items as $item) {
                    $product = $products->get($item['product_uuid']);

                    if ($product === null) {
                        throw new InvalidArgumentException(
                            sprintf('Producto %s no encontrado en este tenant.', $item['product_uuid'])
                        );
                    }

                    $calc = $this->calculateLine(
                        (float) $item['quantity'],
                        (float) $item['unit_cost'],
                        $product->tax !== null ? (float) $product->tax->rate : 0.0,
                    );

                    $lines[] = ['product_id' => $product->id] + $calc;
                    $subtotal += $calc['subtotal'];
                    $taxTotal += $calc['tax_amount'];
                }

                $locked->items()->delete();

                foreach ($lines as $line) {
                    PurchaseOrderItem::create([
                        'company_id' => TenantContext::id(),
                        'purchase_order_id' => $locked->id,
                    ] + $line);
                }

                $cambios['subtotal'] = round($subtotal, 2);
                $cambios['tax_total'] = round($taxTotal, 2);
                $cambios['total'] = round($subtotal + $taxTotal, 2);
            }

            if ($cambios !== []) {
                $locked->update($cambios);
            }

            return $locked->fresh(['items']);
        });
    }

    public function submit(PurchaseOrder $order): PurchaseOrder
    {
        return $this->transition($order, [PurchaseOrder::STATUS_DRAFT], PurchaseOrder::STATUS_SUBMITTED, 'enviar', [
            'submitted_at' => now(),
        ]);
    }

    public function approve(PurchaseOrder $order, User $user): PurchaseOrder
    {
        return $this->transition($order, [PurchaseOrder::STATUS_SUBMITTED], PurchaseOrder::STATUS_APPROVED, 'aprobar', [
            'approved_at' => now(),
            'approved_by' => $user->id,
        ]);
    }

    public function cancel(PurchaseOrder $order): PurchaseOrder
    {
        return $this->transition($order, [
            PurchaseOrder::STATUS_DRAFT,
            PurchaseOrder::STATUS_SUBMITTED,
            PurchaseOrder::STATUS_APPROVED,
        ], PurchaseOrder::STATUS_CANCELLED, 'cancelar', [
            'cancelled_at' => now(),
        ]);
    }

    /**
     * Recepcion de mercancia contra una OC aprobada.
     *
     * Acumula sobre quantity_received y mueve stock via InventoryService,
     * una entrada por linea con la OC como source. La OC pasa a received
     * solo cuando TODAS las lineas alcanzan la cantidad pedida; con
     * recepcion parcial permanece en approved.
     *
     * DIFERIDO: lotes y caducidad. recordEntry acepta $batch, pero capturar
     * lote exige tracks_lots y validacion propia; va en su entrega.
     *
     * @param  array<int, array{product_uuid: string, quantity: float}>  $items
     */
    public function receive(
        PurchaseOrder $order,
        string $warehouseUuid,
        array $items,
        User $user,
    ): PurchaseOrder {
        if ($items === []) {
            throw new InvalidArgumentException('Una recepcion necesita al menos una linea.');
        }

        return DB::transaction(function () use ($order, $warehouseUuid, $items, $user) {
            /** @var PurchaseOrder $locked */
            $locked = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PurchaseOrder::STATUS_APPROVED) {
                throw PurchaseOrderTransitionException::forStatus($locked->status, 'recibir');
            }

            $warehouse = Warehouse::query()
                ->where('uuid', $warehouseUuid)
                ->where('branch_id', $locked->branch_id)
                ->where('is_active', true)
                ->firstOrFail();

            $lineas = $locked->items()->with('product')->get()->keyBy(
                fn (PurchaseOrderItem $i): string => (string) $i->product->uuid
            );

            foreach ($items as $item) {
                /** @var PurchaseOrderItem|null $linea */
                $linea = $lineas->get($item['product_uuid']);

                if ($linea === null) {
                    throw new InvalidArgumentException(
                        sprintf('El producto %s no pertenece a esta orden.', $item['product_uuid'])
                    );
                }

                $cantidad = (float) $item['quantity'];

                if ($linea->product->tracks_lots && ($item['batch'] ?? null) === null) {
                    throw new InvalidArgumentException(sprintf(
                        'El producto %s maneja lotes; la recepcion debe capturar batch.',
                        $item['product_uuid'],
                    ));
                }
                $pendiente = round((float) $linea->quantity - (float) $linea->quantity_received, 4);

                if ($cantidad > $pendiente) {
                    throw new InvalidArgumentException(sprintf(
                        'La cantidad recibida (%s) excede lo pendiente (%s) del producto %s.',
                        $cantidad,
                        $pendiente,
                        $item['product_uuid'],
                    ));
                }

                $linea->update([
                    'quantity_received' => round((float) $linea->quantity_received + $cantidad, 4),
                ]);

                $this->inventory->recordEntry(
                    product: $linea->product,
                    warehouse: $warehouse,
                    quantity: $cantidad,
                    unitCost: (float) $linea->unit_cost,
                    type: InventoryMovement::TYPE_ENTRY,
                    reason: 'Recepcion de OC '.$locked->folio,
                    reference: $locked->folio,
                    source: $locked,
                    userId: $user->id,
                    batch: $item['batch'] ?? null,
                    supplierId: $locked->supplier_id,
                    purchaseOrderId: $locked->id,
                );
            }

            $completa = $locked->items()->get()->every(
                fn (PurchaseOrderItem $i): bool => (float) $i->quantity_received >= (float) $i->quantity
            );

            if ($completa) {
                $locked->update([
                    'status' => PurchaseOrder::STATUS_RECEIVED,
                    'received_at' => now(),
                ]);
            }

            return $locked->fresh(['items']);
        });
    }

    /**
     * @param  list<string>  $desde
     * @param  array<string, mixed>  $extra
     */

    /**
     * Productos del proveedor (maestro 29.7, GET /suppliers/{uuid}/products).
     *
     * Fuente: purchase_order_items de OCs del proveedor en cualquier status
     * excepto cancelled (una OC cancelada no establece relacion comercial;
     * una draft si expresa intencion). Productos comprados sin OC no son
     * listables por proveedor: ver [deuda-19] en DEUDA_TECNICA.md.
     */
    public function productsForSupplier(Supplier $supplier, int $perPage): LengthAwarePaginator
    {
        return Product::query()
            ->where(function ($q) use ($supplier) {
                $q->whereIn('id', PurchaseOrderItem::query()
                    ->whereIn('purchase_order_id', PurchaseOrder::query()
                        ->where('supplier_id', $supplier->id)
                        ->where('status', '!=', PurchaseOrder::STATUS_CANCELLED)
                        ->select('id'))
                    ->select('product_id'))
                    ->orWhereIn('id', Batch::query()
                        ->where('supplier_id', $supplier->id)
                        ->select('product_id'))
                    ->orWhereIn('id', InventoryMovement::query()
                        ->where('supplier_id', $supplier->id)
                        ->select('product_id'));
            })
            ->orderBy('id')
            ->paginate($perPage);
    }

    private function transition(
        PurchaseOrder $order,
        array $desde,
        string $hacia,
        string $accion,
        array $extra = [],
    ): PurchaseOrder {
        return DB::transaction(function () use ($order, $desde, $hacia, $accion, $extra) {
            /** @var PurchaseOrder $locked */
            $locked = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, $desde, true)) {
                throw PurchaseOrderTransitionException::forStatus($locked->status, $accion);
            }

            $locked->update(['status' => $hacia] + $extra);

            return $locked->fresh(['items']);
        });
    }

    /**
     * @return array{quantity: float, quantity_received: float, unit_cost: float, tax_rate: float, tax_amount: float, subtotal: float, total: float}
     */
    private function calculateLine(float $quantity, float $unitCost, float $taxRate): array
    {
        $subtotal = round($quantity * $unitCost, 2);
        $taxAmount = round($subtotal * $taxRate, 2);

        return [
            'quantity' => $quantity,
            'quantity_received' => 0,
            'unit_cost' => $unitCost,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'subtotal' => $subtotal,
            'total' => round($subtotal + $taxAmount, 2),
        ];
    }

    /**
     * Consecutivo por tenant. Sin lockForUpdate: Postgres no admite FOR
     * UPDATE sobre agregados (SQLSTATE 0A000). withTrashed() evita reciclar
     * un folio si la ultima OC del tenant fue borrada logicamente.
     *
     * DIFERIDO: bajo alta concurrencia dos OC simultaneas del mismo tenant
     * pueden colisionar; la red es el unique (company_id, folio). Si el
     * volumen lo justifica, migrar al patron de SaleNumberCounter (fila
     * contador con SELECT ... FOR UPDATE), que no obliga a tocar esta tabla.
     */
    private function nextFolio(): string
    {
        $ultimo = PurchaseOrder::query()
            ->withTrashed()
            ->where('company_id', TenantContext::id())
            ->where('folio', 'like', 'OC-%')
            ->orderByDesc('folio')
            ->value('folio');

        $n = $ultimo !== null ? (int) substr($ultimo, 3) : 0;

        return 'OC-'.str_pad((string) ($n + 1), 6, '0', STR_PAD_LEFT);
    }
}
