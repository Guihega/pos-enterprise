<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Purchasing\Exceptions\SupplierInvoiceTransitionException;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\PurchaseOrderItem;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Purchasing\Models\SupplierInvoice;
use App\Domain\Purchasing\Models\SupplierPayment;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Facturas de proveedor y pagos (4.1.5, maestro 29.7).
 *
 * El maestro define las tablas (4541-4577) pero NO la semantica de match ni
 * de pay: no hay prosa, reglas de negocio ni casos de uso que las mencionen.
 * Las decisiones estan en el docblock de cada metodo y en la migracion 000050.
 */
class SupplierInvoiceService
{
    /**
     * Alta de factura. El folio lo emite el proveedor: se captura.
     *
     * purchase_order_id NO se acepta aqui: vincular es responsabilidad de
     * match(), que ademas valida importes. Permitirlo en el alta seria una
     * segunda via de vinculacion sin validar.
     */
    public function create(
        string $supplierUuid,
        string $folio,
        string $issueDate,
        string $dueDate,
        float $subtotal,
        float $taxTotal,
        ?string $cfdiUuid = null,
        ?string $cfdiXmlUrl = null,
        ?string $paymentMethod = null,
        ?string $notes = null,
    ): SupplierInvoice {
        if ($subtotal < 0 || $taxTotal < 0) {
            throw new InvalidArgumentException('Los importes de la factura no pueden ser negativos.');
        }

        return DB::transaction(function () use (
            $supplierUuid, $folio, $issueDate, $dueDate, $subtotal,
            $taxTotal, $cfdiUuid, $cfdiXmlUrl, $paymentMethod, $notes
        ) {
            $supplier = Supplier::query()->where('uuid', $supplierUuid)->firstOrFail();

            // Capturar dos veces la misma factura es el error de captura mas
            // comun. Sin esto el unique salta como QueryException y el handler
            // la convierte en 500: un error del usuario devuelto como fallo del
            // servidor. La red del unique se queda como garantia de carrera.
            $repetida = SupplierInvoice::query()
                ->where('supplier_id', $supplier->id)
                ->where('folio', $folio)
                ->exists();

            if ($repetida) {
                throw new InvalidArgumentException(sprintf(
                    'Este proveedor ya tiene registrada una factura con folio %s.',
                    $folio,
                ));
            }

            $invoice = SupplierInvoice::create([
                'uuid' => (string) Str::uuid(),
                'company_id' => TenantContext::id(),
                'supplier_id' => $supplier->id,
                'folio' => $folio,
                'cfdi_uuid' => $cfdiUuid,
                'cfdi_xml_url' => $cfdiXmlUrl,
                'issue_date' => $issueDate,
                'due_date' => $dueDate,
                'subtotal' => round($subtotal, 4),
                'tax_total' => round($taxTotal, 4),
                'total' => round($subtotal + $taxTotal, 4),
                'payment_method' => $paymentMethod,
                'notes' => $notes,
            ]);

            // status y paid_amount NO son fillable: sus valores los pone el
            // DEFAULT de la columna, que create() no conoce. Sin refresh el
            // objeto devuelto trae status null aunque la fila este correcta.
            return $invoice->refresh();
        });
    }

    /**
     * Concilia la factura contra una OC comparando con lo RECIBIDO.
     *
     * Decision de alcance: no se compara contra lo PEDIDO. Una OC recibida
     * parcialmente factura menos que su total, y comparar contra el total
     * generaria un 422 falso. Tampoco se concilia linea a linea: el maestro
     * no define tabla de lineas de factura, solo totales.
     *
     * Regla: facturado <= recibido pasa; el EXCESO es 422. Mismo criterio que
     * receive() con las cantidades. La factura anticipada (facturar antes de
     * recibir) queda bloqueada a proposito.
     *
     * Comparacion EXACTA sin tolerancia: ambos lados son decimal(18,4) y se
     * redondean por la misma via, asi que un centavo de diferencia es real.
     */
    public function match(SupplierInvoice $invoice, string $purchaseOrderUuid): SupplierInvoice
    {
        return DB::transaction(function () use ($invoice, $purchaseOrderUuid) {
            /** @var SupplierInvoice $locked */
            $locked = SupplierInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($locked->purchase_order_id !== null) {
                throw SupplierInvoiceTransitionException::forStatus($locked->status, 'conciliar una factura ya conciliada de');
            }

            $order = PurchaseOrder::query()->where('uuid', $purchaseOrderUuid)->firstOrFail();

            if ($order->supplier_id !== $locked->supplier_id) {
                throw new InvalidArgumentException('La orden de compra pertenece a otro proveedor.');
            }

            $recibido = $this->valorRecibido($order);

            if (round((float) $locked->total, 4) > $recibido) {
                throw new InvalidArgumentException(sprintf(
                    'El total facturado (%s) excede el valor recibido de la orden (%s).',
                    round((float) $locked->total, 4),
                    $recibido,
                ));
            }

            $locked->update(['purchase_order_id' => $order->id]);

            return $locked->fresh(['supplier', 'purchaseOrder']);
        });
    }

    /**
     * Registra un pago y deriva el status de la factura.
     *
     * status es funcion pura de paid_amount (0 pending, < total partial,
     * = total paid), nunca se fija a mano. paid_amount y status no son
     * fillable: se escriben por asignacion directa.
     *
     * Sobrepago = 422, mismo criterio que el exceso en receive() y en match().
     */
    public function pay(
        SupplierInvoice $invoice,
        float $amount,
        string $paymentDate,
        string $method,
        User $user,
        ?string $reference = null,
        ?string $notes = null,
    ): SupplierInvoice {
        if ($amount <= 0) {
            throw new InvalidArgumentException('El importe del pago debe ser mayor que cero.');
        }

        return DB::transaction(function () use ($invoice, $amount, $paymentDate, $method, $user, $reference, $notes) {
            /** @var SupplierInvoice $locked */
            $locked = SupplierInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === SupplierInvoice::STATUS_PAID) {
                throw SupplierInvoiceTransitionException::forStatus($locked->status, 'pagar');
            }

            if ($locked->status === SupplierInvoice::STATUS_CANCELLED) {
                throw SupplierInvoiceTransitionException::forStatus($locked->status, 'pagar');
            }

            $saldo = round((float) $locked->total - (float) $locked->paid_amount, 4);

            if (round($amount, 4) > $saldo) {
                throw new InvalidArgumentException(sprintf(
                    'El pago (%s) excede el saldo pendiente (%s).',
                    round($amount, 4),
                    $saldo,
                ));
            }

            SupplierPayment::create([
                'uuid' => (string) Str::uuid(),
                'company_id' => TenantContext::id(),
                'supplier_id' => $locked->supplier_id,
                'invoice_id' => $locked->id,
                'folio' => $this->nextFolio(),
                'payment_date' => $paymentDate,
                'amount' => round($amount, 4),
                'method' => $method,
                'reference' => $reference,
                'user_id' => $user->id,
                'notes' => $notes,
            ]);

            $pagado = round((float) $locked->paid_amount + $amount, 4);
            $locked->paid_amount = $pagado;
            $locked->status = $pagado >= round((float) $locked->total, 4)
                ? SupplierInvoice::STATUS_PAID
                : SupplierInvoice::STATUS_PARTIAL;
            $locked->save();

            return $locked->fresh(['supplier', 'payments']);
        });
    }

    /**
     * Valor efectivamente recibido de una OC, con impuestos.
     *
     * quantity_received * unit_cost * (1 + tax_rate). unit_cost es SIEMPRE
     * neto (000047) y tax_rate es fraccion, no porcentaje.
     *
     * La formula esta DUPLICADA respecto a calculateLine() de
     * PurchaseOrderService a proposito: aquel es privado y calcula sobre
     * quantity, no sobre quantity_received. Exponerlo o adaptarlo obligaria a
     * modificar un servicio ya entregado y probado.
     */
    private function valorRecibido(PurchaseOrder $order): float
    {
        $total = 0.0;

        foreach ($order->items()->get() as $linea) {
            /** @var PurchaseOrderItem $linea */
            $neto = (float) $linea->quantity_received * (float) $linea->unit_cost;
            $total += $neto * (1 + (float) $linea->tax_rate);
        }

        return round($total, 4);
    }

    /**
     * Consecutivo por tenant. Sin lockForUpdate: Postgres no admite FOR UPDATE
     * sobre agregados (SQLSTATE 0A000, leccion 15).
     *
     * SIN withTrashed(), a diferencia de PurchaseOrderService: SupplierPayment
     * no usa SoftDeletes (un pago no se borra, se contra-registra), asi que el
     * metodo no existe y tampoco hace falta: sin borrado logico max(id) no
     * recicla consecutivos.
     *
     * DIFERIDO: bajo alta concurrencia dos pagos simultaneos del mismo tenant
     * pueden colisionar; la red es el unique (company_id, folio).
     */
    private function nextFolio(): string
    {
        $ultimo = SupplierPayment::query()
            ->where('company_id', TenantContext::id())
            ->where('folio', 'like', 'PAY-%')
            ->orderByDesc('folio')
            ->value('folio');

        $n = $ultimo !== null ? (int) substr($ultimo, 4) : 0;

        return 'PAY-'.str_pad((string) ($n + 1), 6, '0', STR_PAD_LEFT);
    }
}
