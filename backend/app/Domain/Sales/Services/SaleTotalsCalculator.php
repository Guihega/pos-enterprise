<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Sales\Dto\CheckoutItem;

/**
 * Calculadora de totales de una línea de venta.
 *
 * SIN efectos secundarios. Toma un Product (con su tax cargado) y un
 * CheckoutItem, devuelve un array con todos los campos calculados listos
 * para insertar en sale_items.
 *
 * Soporta tax inclusive (precio con IVA) y exclusive (precio sin IVA).
 *
 * Cálculo:
 *   Si tax_exclusive:
 *     line_subtotal     = qty × unit_price                       (base sin IVA)
 *     after_discount    = line_subtotal - discount_amount
 *     tax_amount        = after_discount × tax_rate
 *     line_total        = after_discount + tax_amount
 *
 *   Si tax_inclusive (precio YA incluye IVA):
 *     line_subtotal     = qty × unit_price                       (incluye IVA)
 *     after_discount    = line_subtotal - discount_amount
 *     # Extraer el IVA del precio inclusivo
 *     line_total        = after_discount
 *     tax_amount        = after_discount - (after_discount / (1 + tax_rate))
 *     base_taxable_neto = after_discount - tax_amount            (para sumar a subtotal de venta)
 *
 * El "subtotal de la venta" siempre se calcula como sum(base_taxable_neto)
 * para que la fórmula final sea consistente: total = subtotal + tax + tip.
 */
final class SaleTotalsCalculator
{
    /**
     * Calcula los totales de una línea.
     *
     * @return array{
     *   product_sku: string,
     *   product_name: string,
     *   unit_name: string|null,
     *   quantity: float,
     *   unit_price: float,
     *   line_subtotal: float,
     *   discount_percent: float,
     *   discount_amount: float,
     *   is_taxable: bool,
     *   tax_inclusive: bool,
     *   tax_rate: float,
     *   tax_amount: float,
     *   tax_code: string|null,
     *   line_total: float,
     *   track_inventory: bool,
     *   base_taxable_neto: float,
     * }
     */
    public function calculateLine(Product $product, CheckoutItem $request): array
    {
        $qty = $request->quantity;
        $unitPrice = $request->unitPriceOverride ?? (float) $product->price;
        $lineSubtotal = round($qty * $unitPrice, 2);

        // Descuento
        $discountAmount = $request->discountAmountOverride;
        if ($discountAmount === null) {
            $discountAmount = round($lineSubtotal * ($request->discountPercent / 100), 2);
        }
        $discountAmount = max(0.0, min($discountAmount, $lineSubtotal));
        $afterDiscount = round($lineSubtotal - $discountAmount, 2);

        // Impuesto
        $tax = $product->tax;
        // Un producto está sujeto a impuesto cuando tiene tax_id asignado
        // (la relación tax cargada). El campo "is_taxable" no existe en products.
        $isTaxable = $tax !== null;
        $taxRate = $isTaxable ? (float) $tax->rate : 0.0;
        $taxInclusive = $isTaxable ? (bool) $tax->is_inclusive : false;
        $taxCode = $isTaxable ? $tax->code : null;

        if (! $isTaxable) {
            $taxAmount = 0.0;
            $lineTotal = $afterDiscount;
            $baseTaxableNeto = $afterDiscount;
        } elseif ($taxInclusive) {
            // El precio YA incluye el IVA. Extraerlo.
            $lineTotal = $afterDiscount;
            $baseTaxableNeto = round($afterDiscount / (1 + $taxRate), 2);
            $taxAmount = round($afterDiscount - $baseTaxableNeto, 2);
        } else {
            // Tax exclusive: agregar IVA encima del precio
            $taxAmount = round($afterDiscount * $taxRate, 2);
            $lineTotal = round($afterDiscount + $taxAmount, 2);
            $baseTaxableNeto = $afterDiscount;
        }

        return [
            'product_sku' => $product->sku,
            'product_name' => $product->name,
            'unit_name' => $product->unit?->name,
            'quantity' => $qty,
            'unit_price' => $unitPrice,
            'line_subtotal' => $lineSubtotal,
            'discount_percent' => $request->discountPercent,
            'discount_amount' => $discountAmount,
            'is_taxable' => $isTaxable,
            'tax_inclusive' => $taxInclusive,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'tax_code' => $taxCode,
            'line_total' => $lineTotal,
            'track_inventory' => (bool) $product->track_inventory,
            'base_taxable_neto' => $baseTaxableNeto,
        ];
    }

    /**
     * Calcula los totales del encabezado a partir de las líneas calculadas.
     *
     * @param  array<int, array<string, mixed>>  $lines  Output de calculateLine
     * @return array{
     *   subtotal_amount: float,
     *   discount_amount: float,
     *   tax_amount: float,
     *   total_amount: float,
     *   taxes_breakdown: array<string, array{code: string, rate: float, taxable_base: float, amount: float}>,
     * }
     */
    public function calculateSale(array $lines, float $tipAmount = 0): array
    {
        $subtotal = 0.0;       // sum de base_taxable_neto
        $discount = 0.0;
        $taxTotal = 0.0;
        $byCode = [];          // breakdown por código de impuesto

        foreach ($lines as $line) {
            $subtotal += (float) $line['base_taxable_neto'];
            $discount += (float) $line['discount_amount'];
            $taxTotal += (float) $line['tax_amount'];

            if ($line['is_taxable'] && $line['tax_code']) {
                $code = $line['tax_code'];
                if (! isset($byCode[$code])) {
                    $byCode[$code] = [
                        'code' => $code,
                        'rate' => (float) $line['tax_rate'],
                        'taxable_base' => 0.0,
                        'amount' => 0.0,
                    ];
                }
                $byCode[$code]['taxable_base'] += (float) $line['base_taxable_neto'];
                $byCode[$code]['amount'] += (float) $line['tax_amount'];
            }
        }

        // Redondear los breakdowns finales
        foreach ($byCode as $code => &$bd) {
            $bd['taxable_base'] = round($bd['taxable_base'], 2);
            $bd['amount'] = round($bd['amount'], 2);
        }

        $subtotal = round($subtotal, 2);
        $discount = round($discount, 2);
        $taxTotal = round($taxTotal, 2);
        $tipAmount = round($tipAmount, 2);
        $total = round($subtotal + $taxTotal + $tipAmount, 2);

        return [
            'subtotal_amount' => $subtotal,
            'discount_amount' => $discount,
            'tax_amount' => $taxTotal,
            'total_amount' => $total,
            'taxes_breakdown' => $byCode,
        ];
    }
}
