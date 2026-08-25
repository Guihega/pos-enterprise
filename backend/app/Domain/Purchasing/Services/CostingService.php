<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Purchasing\Exceptions\CostingRunTransitionException;
use App\Domain\Purchasing\Models\CostingRun;
use App\Domain\Purchasing\Models\CostingRunLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Corridas de costeo: landed cost -> precio sugerido (DISENO_COSTEO.md).
 *
 * Formulas por linea (todo fraccion, redondeo SOLO al final):
 *   units      = units_per_pack * packs_qty
 *   base_unit  = pack_price / units_per_pack
 *   freight_u  = (freight+other del run) * (valor_linea / valor_total) / units
 *                donde valor_linea = pack_price * packs_qty (D4: por valor)
 *   unit_cost  = (base_unit + freight_u + extra_cost/units) / (1 - waste_pct)
 *   precio     = markup:   unit_cost * (1 + margin_pct)
 *                on_price: unit_cost / (1 - margin_pct)
 *
 * Si el run no tiene valor total (> 0) el prorrateo es 0 para toda linea.
 */
class CostingService
{
    /**
     * Crea la corrida en draft con sus lineas y calcula el preview.
     *
     * @param array<int, array{product_uuid: string, pack_description: string,
     *   pack_price: float, units_per_pack: float, packs_qty: float,
     *   extra_cost?: float, waste_pct?: float, margin_pct?: float,
     *   margin_type?: string}> $lines
     */
    public function create(
        string $name,
        array $lines,
        float $freightTotal = 0,
        float $otherCostsTotal = 0,
        ?string $notes = null,
        ?int $userId = null,
    ): CostingRun {
        if ($lines === []) {
            throw new InvalidArgumentException('La corrida requiere al menos una linea.');
        }
        if ($freightTotal < 0 || $otherCostsTotal < 0) {
            throw new InvalidArgumentException('Los costos del viaje no pueden ser negativos.');
        }

        return DB::transaction(function () use (
            $name, $lines, $freightTotal, $otherCostsTotal, $notes, $userId
        ): CostingRun {
            $uuids = array_column($lines, 'product_uuid');
            $products = Product::query()
                ->whereIn('uuid', $uuids)
                ->get()
                ->keyBy('uuid');
            $missing = array_diff($uuids, $products->keys()->all());
            if ($missing !== []) {
                throw new InvalidArgumentException(
                    sprintf('Productos inexistentes: %s.', implode(', ', $missing))
                );
            }

            $run = CostingRun::query()->create([
                'uuid' => (string) Str::uuid(),
                'name' => $name,
                'freight_total' => $freightTotal,
                'other_costs_total' => $otherCostsTotal,
                'notes' => $notes,
                'created_by' => $userId,
            ]);

            foreach ($lines as $line) {
                $this->validateLine($line);
                $run->lines()->create([
                    'company_id' => $run->company_id,
                    'product_id' => $products[$line['product_uuid']]->id,
                    'pack_description' => $line['pack_description'],
                    'pack_price' => $line['pack_price'],
                    'units_per_pack' => $line['units_per_pack'],
                    'packs_qty' => $line['packs_qty'],
                    'extra_cost' => $line['extra_cost'] ?? 0,
                    'waste_pct' => $line['waste_pct'] ?? 0,
                    'margin_pct' => $line['margin_pct'] ?? 0,
                    'margin_type' => $line['margin_type'] ?? CostingRunLine::MARGIN_MARKUP,
                ]);
            }

            $this->compute($run);

            return $run->refresh()->load('lines');
        });
    }

    /**
     * Calcula y persiste computed_* de cada linea. Idempotente: recalcular
     * un draft es gratis; el preview del GET siempre refleja los datos.
     */
    public function compute(CostingRun $run): CostingRun
    {
        $run->loadMissing('lines');

        $poolTotal = round($run->freight_total + $run->other_costs_total, 4);
        $valorTotal = 0.0;
        foreach ($run->lines as $line) {
            $valorTotal += $line->pack_price * $line->packs_qty;
        }

        foreach ($run->lines as $line) {
            $units = $line->units_per_pack * $line->packs_qty;
            $baseUnit = $line->pack_price / $line->units_per_pack;

            $freightUnit = 0.0;
            if ($valorTotal > 0 && $poolTotal > 0) {
                $valorLinea = $line->pack_price * $line->packs_qty;
                $freightUnit = $poolTotal * ($valorLinea / $valorTotal) / $units;
            }

            $unitCost = ($baseUnit + $freightUnit + $line->extra_cost / $units)
                / (1 - $line->waste_pct);

            $price = $line->margin_type === CostingRunLine::MARGIN_ON_PRICE
                ? $unitCost / (1 - $line->margin_pct)
                : $unitCost * (1 + $line->margin_pct);

            $line->computed_units = round($units, 4);
            $line->computed_unit_cost = round($unitCost, 4);
            $line->computed_price = round($price, 4);
            $line->save();
        }

        return $run;
    }

    /**
     * Confirma la corrida: recalcula sobre los datos actuales, escribe
     * products.cost (D1=b: el price NO se toca, queda sugerido en la
     * linea) y marca confirmed, que es terminal.
     */
    public function confirm(CostingRun $run): CostingRun
    {
        if ($run->status === CostingRun::STATUS_CONFIRMED) {
            throw new CostingRunTransitionException(
                'La corrida ya esta confirmada; confirmed es terminal.'
            );
        }

        return DB::transaction(function () use ($run): CostingRun {
            $this->compute($run);

            foreach ($run->lines as $line) {
                Product::query()
                    ->whereKey($line->product_id)
                    ->update(['cost' => $line->computed_unit_cost]);
            }

            $run->status = CostingRun::STATUS_CONFIRMED;
            $run->confirmed_at = now();
            $run->save();

            return $run->refresh()->load('lines');
        });
    }

    /**
     * @param array<string, mixed> $line
     */
    private function validateLine(array $line): void
    {
        if (($line['pack_price'] ?? 0) < 0 || ($line['extra_cost'] ?? 0) < 0) {
            throw new InvalidArgumentException('Los costos de una linea no pueden ser negativos.');
        }
        if (($line['units_per_pack'] ?? 0) <= 0 || ($line['packs_qty'] ?? 0) <= 0) {
            throw new InvalidArgumentException('units_per_pack y packs_qty deben ser mayores a cero.');
        }
        $waste = $line['waste_pct'] ?? 0;
        if ($waste < 0 || $waste >= 1) {
            throw new InvalidArgumentException('waste_pct debe ser fraccion en [0, 1): 0.05 es 5%.');
        }
        $margin = $line['margin_pct'] ?? 0;
        $type = $line['margin_type'] ?? CostingRunLine::MARGIN_MARKUP;
        if (! in_array($type, [CostingRunLine::MARGIN_MARKUP, CostingRunLine::MARGIN_ON_PRICE], true)) {
            throw new InvalidArgumentException('margin_type debe ser markup u on_price.');
        }
        if ($margin < 0 || ($type === CostingRunLine::MARGIN_ON_PRICE && $margin >= 1)) {
            throw new InvalidArgumentException('margin_pct invalido: on_price exige fraccion en [0, 1).');
        }
    }
}
