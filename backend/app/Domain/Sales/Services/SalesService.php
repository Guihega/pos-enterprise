<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Authorization\Roles;
use App\Domain\Cash\Exceptions\CashSessionNotOpenException;
use App\Domain\Cash\Models\CashMovement;
use App\Domain\Cash\Models\CashSession;
use App\Domain\Cash\Services\CashService;
use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\InventoryMovement;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Sales\Dto\CheckoutPayment;
use App\Domain\Sales\Dto\CheckoutRequest;
use App\Domain\Sales\Exceptions\InsufficientCreditException;
use App\Domain\Sales\Exceptions\PaymentMismatchException;
use App\Domain\Sales\Exceptions\SaleNotCancellableException;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleItem;
use App\Domain\Sales\Models\SaleItemBatch;
use App\Domain\Sales\Models\SalePayment;
use App\Domain\Sales\Models\SaleTax;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Servicio principal de ventas.
 *
 * Operaciones:
 *   - checkout(): completa una venta (crear sale + items + payments,
 *     descontar stock vía InventoryService, registrar cash movements
 *     vía CashService, generar folio único). Todo atómico.
 *   - cancel(): cancela una venta completed (revierte stock y caja
 *     con movimientos compensatorios). Atómico.
 *
 * Reglas:
 *   - La cash_session debe estar abierta.
 *   - Sum(payments.amount) == total_amount, o == total_amount + change si
 *     hay cash con tendered_amount > amount.
 *   - Si method=credit, customer es obligatorio y debe tener crédito.
 */
final class SalesService
{
    /**
     * EX-079 / RN-197: numero de cancelaciones (voided) del mismo cajero en el
     * dia que dispara la alerta de fraude al auditor. El maestro lo enuncia
     * como "10 ventas seguidas"; se toma como umbral configurable (punto de
     * configuracion unico hasta que exista settings de tenant).
     */
    public const MASS_CANCELLATION_THRESHOLD = 10;

    /**
     * RN-196: una venta cuyo total supera este monto genera alerta de fraude
     * al admin. El maestro lo enuncia como "venta >X" configurable; se toma
     * como umbral configurable (punto de configuracion unico hasta que exista
     * settings de tenant).
     */
    public const LARGE_SALE_THRESHOLD = 50000.0;

    public function __construct(
        private readonly SaleNumberGenerator $numberGenerator,
        private readonly SaleTotalsCalculator $calculator,
        private readonly InventoryService $inventory,
        private readonly CashService $cash,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Checkout: registra una venta completa.
     */
    public function checkout(CheckoutRequest $request, User $user): Sale
    {
        $sale = DB::transaction(function () use ($request, $user) {
            // 1. Resolver la sesión de caja, validar que esté abierta
            /** @var CashSession $session */
            $session = CashSession::query()
                ->where('uuid', $request->cashSessionUuid)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $session->isOpen()) {
                throw new CashSessionNotOpenException(
                    "La sesión {$session->id} no está abierta (status: {$session->status})"
                );
            }

            // 2. Resolver almacén
            /** @var Warehouse $warehouse */
            $warehouse = Warehouse::query()
                ->where('uuid', $request->warehouseUuid)
                ->firstOrFail();

            // 3. Resolver cliente (opcional)
            $customer = null;
            $customerName = $request->customerName;
            $customerTaxId = $request->customerTaxId;
            if ($request->customerUuid !== null) {
                /** @var Customer $customer */
                $customer = Customer::query()
                    ->where('uuid', $request->customerUuid)
                    ->lockForUpdate()  // por si method=credit
                    ->firstOrFail();
                $customerName = $customer->name;
                $customerTaxId = $customer->tax_id;
            }

            // 4. Cargar productos en una sola query y construir mapa por UUID
            $productUuids = array_map(fn ($i) => $i->productUuid, $request->items);
            $products = Product::query()
                ->whereIn('uuid', $productUuids)
                ->with(['tax', 'unit'])
                ->get()
                ->keyBy('uuid');

            // 5. Calcular líneas
            $linesData = [];  // datos calculados, listos para insertar como SaleItem
            foreach ($request->items as $itemRequest) {
                $product = $products->get($itemRequest->productUuid);
                if (! $product) {
                    throw new \InvalidArgumentException(
                        "Producto {$itemRequest->productUuid} no encontrado en este tenant"
                    );
                }
                $linesData[] = [
                    'product' => $product,
                    'calc' => $this->calculator->calculateLine($product, $itemRequest),
                ];
            }

            // 6. Calcular totales del encabezado
            $totals = $this->calculator->calculateSale(
                array_map(fn ($l) => $l['calc'], $linesData),
                $request->tipAmount
            );

            // 7. Validar pagos vs total
            $this->validatePayments($request->payments, $totals['total_amount']);

            // 8. Si hay pago con method=credit, validar crédito disponible
            $this->validateCreditPayment($request->payments, $customer, $totals['total_amount']);

            // 9. Generar folio único (lock pesimista interno)
            $folioData = $this->numberGenerator->next(
                $warehouse->branch,
                $session->register,
                $request->series
            );

            // 10. Crear el encabezado de la venta
            $sale = Sale::create([
                'uuid' => (string) Str::uuid(),
                'company_id' => TenantContext::id(),
                'number' => $folioData['number'],
                'series' => $request->series,
                'number_value' => $folioData['value'],
                'branch_id' => $session->branch_id,
                'cash_register_id' => $session->cash_register_id,
                'cash_session_id' => $session->id,
                'warehouse_id' => $warehouse->id,
                'customer_id' => $customer?->id,
                'customer_name' => $customerName,
                'customer_tax_id' => $customerTaxId,
                'customer_data' => $customer?->tax_data ?? [],
                'user_id' => $user->id,
                'status' => Sale::STATUS_COMPLETED,
                'currency_code' => 'MXN',
                'subtotal_amount' => $totals['subtotal_amount'],
                'discount_amount' => $totals['discount_amount'],
                'tax_amount' => $totals['tax_amount'],
                'tip_amount' => round($request->tipAmount, 2),
                'total_amount' => $totals['total_amount'],
                'paid_amount' => 0,  // se actualiza después de procesar pagos
                'change_amount' => 0,
                'notes' => $request->notes,
                'completed_at' => now(),
            ]);

            // 11. Crear items + descontar stock
            foreach ($linesData as $line) {
                $product = $line['product'];
                $calc = $line['calc'];

                $saleItem = SaleItem::create([
                    'uuid' => (string) Str::uuid(),
                    'company_id' => TenantContext::id(),
                    'sale_id' => $sale->id,
                    'product_id' => $product->id,
                    'product_sku' => $calc['product_sku'],
                    'product_name' => $calc['product_name'],
                    'unit_name' => $calc['unit_name'],
                    'quantity' => $calc['quantity'],
                    'unit_price' => $calc['unit_price'],
                    'unit_cost' => 0,  // se actualiza tras descontar stock con costo promedio
                    'line_subtotal' => $calc['line_subtotal'],
                    'discount_percent' => $calc['discount_percent'],
                    'discount_amount' => $calc['discount_amount'],
                    'is_taxable' => $calc['is_taxable'],
                    'tax_inclusive' => $calc['tax_inclusive'],
                    'tax_rate' => $calc['tax_rate'],
                    'tax_amount' => $calc['tax_amount'],
                    'tax_code' => $calc['tax_code'],
                    'line_total' => $calc['line_total'],
                    'track_inventory' => $calc['track_inventory'],
                ]);

                // Descontar stock SOLO si el producto rastrea inventario
                if ($calc['track_inventory']) {
                    $batchConsumption = null;

                    $this->inventory->recordExit(
                        product: $product,
                        warehouse: $warehouse,
                        quantity: $calc['quantity'],
                        type: InventoryMovement::TYPE_EXIT,
                        reason: "Venta {$sale->number}",
                        reference: $sale->number,
                        source: $sale,
                        userId: $user->id,
                        batchConsumption: $batchConsumption,
                    );

                    // Checkout 9c: detalle de lotes consumidos FEFO (RN-045).
                    foreach ($batchConsumption ?? [] as $consumed) {
                        SaleItemBatch::create([
                            'company_id' => TenantContext::id(),
                            'sale_item_id' => $saleItem->id,
                            'batch_id' => $consumed['batch_id'],
                            'quantity' => $consumed['quantity'],
                            'unit_cost' => $consumed['unit_cost'],
                        ]);
                    }
                }
            }

            // 12. Crear desglose de impuestos por código
            foreach ($totals['taxes_breakdown'] as $bd) {
                SaleTax::create([
                    'company_id' => TenantContext::id(),
                    'sale_id' => $sale->id,
                    'code' => $bd['code'],
                    'name' => $bd['code'],  // se enriquece a futuro consultando taxes table
                    'rate' => $bd['rate'],
                    'taxable_base' => $bd['taxable_base'],
                    'amount' => $bd['amount'],
                ]);
            }

            // 13. Procesar pagos
            $totalPaid = 0.0;
            $totalChange = 0.0;

            foreach ($request->payments as $payment) {
                SalePayment::create([
                    'uuid' => (string) Str::uuid(),
                    'company_id' => TenantContext::id(),
                    'sale_id' => $sale->id,
                    'method' => $payment->method,
                    'amount' => round($payment->amount, 2),
                    'tendered_amount' => $payment->tenderedAmount !== null
                        ? round($payment->tenderedAmount, 2) : null,
                    'reference' => $payment->reference,
                    'authorization_code' => $payment->authorizationCode,
                    'card_brand' => $payment->cardBrand,
                    'card_last4' => $payment->cardLast4,
                    'captured_at' => now(),
                ]);

                $totalPaid += $payment->amount;

                // Pago en efectivo: registrar en caja como sale_cash
                if ($payment->method === SalePayment::METHOD_CASH) {
                    $this->cash->addMovement(
                        session: $session,
                        user: $user,
                        type: CashMovement::TYPE_SALE_CASH,
                        amount: $payment->amount,
                        reason: "Cobro venta {$sale->number}",
                        reference: $sale->number,
                        source: $sale,
                    );
                    if ($payment->tenderedAmount !== null && $payment->tenderedAmount > $payment->amount) {
                        $totalChange += $payment->tenderedAmount - $payment->amount;
                    }
                } elseif ($payment->method === SalePayment::METHOD_CREDIT) {
                    // Crédito: aumenta saldo deudor del cliente
                    if ($customer === null) {
                        throw new \InvalidArgumentException(
                            'Pago con método credit requiere cliente.'
                        );
                    }
                    $customer->credit_balance = (float) $customer->credit_balance + $payment->amount;
                    $customer->save();
                } else {
                    // Otros métodos (tarjeta, transferencia, vale): registrar en caja como
                    // sale_other (informativo, no afecta efectivo físico).
                    $this->cash->addMovement(
                        session: $session,
                        user: $user,
                        type: CashMovement::TYPE_SALE_OTHER,
                        amount: $payment->amount,
                        reason: "Cobro venta {$sale->number} ({$payment->method})",
                        reference: $sale->number,
                        source: $sale,
                    );
                }
            }

            // 14. Actualizar paid_amount y change_amount en la venta
            $sale->update([
                'paid_amount' => round($totalPaid, 2),
                'change_amount' => round($totalChange, 2),
            ]);

            return $sale->fresh(['items', 'payments', 'taxes', 'customer']);
        });

        // RN-196: si el total de la venta supera el umbral, alertar al admin
        // (fraude). Efecto secundario fuera de la transaccion: no debe revertir
        // la venta ya registrada.
        $this->maybeNotifyLargeSale($sale);

        return $sale;
    }

    /**
     * RN-196: notifica al admin cuando una venta supera el umbral de monto.
     * Cada venta grande es un evento independiente (sin guard de repeticion).
     */
    private function maybeNotifyLargeSale(Sale $sale): void
    {
        if ((float) $sale->total_amount <= self::LARGE_SALE_THRESHOLD) {
            return;
        }

        $admins = $this->notifications->usersWithRoles([Roles::ADMIN]);

        foreach ($admins as $admin) {
            $this->notifications->notify(
                recipient: $admin,
                type: 'sales.large_sale',
                data: [
                    'sale_id' => $sale->id,
                    'sale_uuid' => $sale->uuid,
                    'sale_number' => $sale->number,
                    'branch_id' => $sale->branch_id,
                    'total_amount' => (float) $sale->total_amount,
                    'threshold' => self::LARGE_SALE_THRESHOLD,
                    'cashier_id' => $sale->user_id,
                ],
                severity: Notification::SEVERITY_CRITICAL,
            );
        }
    }

    /**
     * Cancela una venta completed: revierte stock y caja con
     * movimientos compensatorios.
     */
    public function cancel(Sale $sale, User $user, string $reason): Sale
    {
        $cancelled = DB::transaction(function () use ($sale, $user, $reason) {
            // Reload con lock
            /** @var Sale $locked */
            $locked = Sale::query()->where('id', $sale->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isCompleted()) {
                throw SaleNotCancellableException::forStatus($locked->status);
            }

            // Reload sesión de caja (debe seguir abierta para revertir cash)
            /** @var CashSession $session */
            $session = CashSession::query()
                ->where('id', $locked->cash_session_id)
                ->lockForUpdate()
                ->firstOrFail();

            $sessionStillOpen = $session->isOpen();

            // 1. Revertir stock (entry compensatoria por cada item con track_inventory)
            $warehouse = Warehouse::find($locked->warehouse_id);
            foreach ($locked->items as $item) {
                if ($item->track_inventory) {
                    $this->inventory->recordEntry(
                        product: $item->product,
                        warehouse: $warehouse,
                        quantity: (float) $item->quantity,
                        unitCost: (float) $item->unit_cost,
                        type: InventoryMovement::TYPE_RETURN_CUSTOMER,
                        reason: "Cancelación venta {$locked->number}: {$reason}",
                        reference: $locked->number,
                        source: $locked,
                        userId: $user->id,
                    );
                }
            }

            // 2. Revertir pagos
            foreach ($locked->payments as $payment) {
                if ($payment->method === SalePayment::METHOD_CASH && $sessionStillOpen) {
                    // Refund cash: solo si la caja sigue abierta
                    $this->cash->addMovement(
                        session: $session,
                        user: $user,
                        type: CashMovement::TYPE_REFUND_CASH,
                        amount: (float) $payment->amount,
                        reason: "Cancelación venta {$locked->number}: {$reason}",
                        reference: $locked->number,
                        source: $locked,
                    );
                } elseif ($payment->method === SalePayment::METHOD_CREDIT) {
                    // Devolver crédito al cliente
                    if ($locked->customer) {
                        $customer = Customer::query()
                            ->where('id', $locked->customer_id)
                            ->lockForUpdate()
                            ->firstOrFail();
                        $customer->credit_balance = max(
                            0.0,
                            (float) $customer->credit_balance - (float) $payment->amount
                        );
                        $customer->save();
                    }
                }
                // Tarjeta/transferencia/etc.: NO se revierten desde aquí
                // (eso requiere proceso bancario manual; queda registrado en notas).
            }

            // 3. Marcar venta como voided
            $locked->update([
                'status' => Sale::STATUS_VOIDED,
                'void_reason' => $reason,
                'voided_by' => $user->id,
                'voided_at' => now(),
            ]);

            return $locked->fresh(['items', 'payments', 'taxes']);
        });

        // EX-079 / RN-197: si el cajero acumula el umbral de cancelaciones en
        // el dia, alertar al auditor (fraude). Efecto secundario fuera de la
        // transaccion: no debe revertir la cancelacion ya realizada.
        $this->maybeNotifyMassCancellation($user);

        return $cancelled;
    }

    /**
     * Cuenta las cancelaciones del cajero en el dia y, si al cruzar el umbral
     * exacto, notifica al auditor. Se dispara solo en el cruce (=== umbral)
     * para no repetir la alerta en cada cancelacion posterior.
     */
    private function maybeNotifyMassCancellation(User $user): void
    {
        $todayVoided = Sale::query()
            ->where('voided_by', $user->id)
            ->where('status', Sale::STATUS_VOIDED)
            ->whereBetween('voided_at', [now()->startOfDay(), now()->endOfDay()])
            ->count();

        if ($todayVoided !== self::MASS_CANCELLATION_THRESHOLD) {
            return;
        }

        $auditors = $this->notifications->usersWithRoles([Roles::AUDITOR]);

        foreach ($auditors as $auditor) {
            $this->notifications->notify(
                recipient: $auditor,
                type: 'sales.mass_cancellation',
                data: [
                    'cashier_id' => $user->id,
                    'cashier_uuid' => $user->uuid,
                    'voided_count' => $todayVoided,
                    'threshold' => self::MASS_CANCELLATION_THRESHOLD,
                ],
                severity: Notification::SEVERITY_CRITICAL,
            );
        }
    }

    // -------------------- Helpers privados --------------------

    /**
     * @param  array<int, CheckoutPayment>  $payments
     */
    private function validatePayments(array $payments, float $totalAmount): void
    {
        if (empty($payments)) {
            throw PaymentMismatchException::underpayment($totalAmount, 0);
        }

        $sum = 0.0;
        foreach ($payments as $p) {
            $sum += $p->amount;
        }
        $sum = round($sum, 2);

        if ($sum < $totalAmount - 0.01) {
            throw PaymentMismatchException::underpayment($totalAmount, $sum);
        }

        // Sobrepago solo permitido si hay pago en efectivo (genera cambio)
        if ($sum > $totalAmount + 0.01) {
            $hasCash = false;
            foreach ($payments as $p) {
                if ($p->method === SalePayment::METHOD_CASH) {
                    $hasCash = true;
                    break;
                }
            }
            if (! $hasCash) {
                throw PaymentMismatchException::overpayWithNonCash();
            }
        }
    }

    /**
     * @param  array<int, CheckoutPayment>  $payments
     */
    private function validateCreditPayment(array $payments, ?Customer $customer, float $totalAmount): void
    {
        $creditAmount = 0.0;
        foreach ($payments as $p) {
            if ($p->method === SalePayment::METHOD_CREDIT) {
                $creditAmount += $p->amount;
            }
        }

        if ($creditAmount <= 0) {
            return;  // no hay pago a crédito, nada que validar
        }

        if ($customer === null) {
            throw new \InvalidArgumentException(
                'Pago con método credit requiere cliente identificado.'
            );
        }

        if (! $customer->canBuyOnCredit($creditAmount)) {
            throw InsufficientCreditException::forCustomer(
                $customer->id,
                $creditAmount,
                $customer->availableCredit()
            );
        }
    }
}
