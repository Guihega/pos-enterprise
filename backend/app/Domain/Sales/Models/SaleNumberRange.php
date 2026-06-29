<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Rango de folios reservado para un dispositivo/caja.
 *
 * ADR-0009: el servidor asigna [range_start, range_end] disjuntos.
 * exhausted_at = NULL => rango activo; NOT NULL => agotado.
 *
 * @property int $id
 * @property int $company_id
 * @property int $cash_register_id
 * @property string $series
 * @property string $device_id
 * @property int $range_start
 * @property int $range_end
 * @property string|null $exhausted_at
 */
class SaleNumberRange extends Model
{
    use BelongsToTenant;

    protected $table = 'sale_number_ranges';

    protected $fillable = [
        'company_id', 'cash_register_id', 'series',
        'device_id', 'range_start', 'range_end', 'exhausted_at',
    ];

    protected $casts = [
        'range_start' => 'integer',
        'range_end' => 'integer',
        'exhausted_at' => 'datetime',
    ];
}
