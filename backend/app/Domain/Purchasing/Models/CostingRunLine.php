<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Models;

use App\Domain\Catalog\Models\Product;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Linea de una corrida de costeo.
 *
 * Sin uuid ni deleted_at: no es entidad de ruta y cuelga de la corrida
 * con cascadeOnDelete, patron de PurchaseOrderItem.
 *
 * computed_* NO son fillable: los escribe SOLO el servicio al calcular,
 * para que una corrida confirmada sea acta de lo decidido.
 *
 * waste_pct, margin_pct son FRACCIONES (0.05 = 5%), igual que taxes.rate.
 *
 * @property int $id
 * @property int $company_id
 * @property int $costing_run_id
 * @property int $product_id
 * @property string $pack_description
 * @property float $pack_price
 * @property float $units_per_pack
 * @property float $packs_qty
 * @property float $extra_cost
 * @property float $waste_pct
 * @property float $margin_pct
 * @property string $margin_type
 * @property float|null $computed_units
 * @property float|null $computed_unit_cost
 * @property float|null $computed_price
 */
class CostingRunLine extends Model
{
    use BelongsToTenant;

    public const MARGIN_MARKUP = 'markup';

    public const MARGIN_ON_PRICE = 'on_price';

    protected $table = 'costing_run_lines';

    protected $fillable = [
        'company_id',
        'costing_run_id',
        'product_id',
        'pack_description',
        'pack_price',
        'units_per_pack',
        'packs_qty',
        'extra_cost',
        'waste_pct',
        'margin_pct',
        'margin_type',
    ];

    protected $casts = [
        'pack_price' => 'float',
        'units_per_pack' => 'float',
        'packs_qty' => 'float',
        'extra_cost' => 'float',
        'waste_pct' => 'float',
        'margin_pct' => 'float',
        'computed_units' => 'float',
        'computed_unit_cost' => 'float',
        'computed_price' => 'float',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(CostingRun::class, 'costing_run_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
