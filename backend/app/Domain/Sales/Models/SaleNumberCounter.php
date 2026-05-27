<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Contador de folios. Usado por el SalesService para generar el siguiente
 * número con SELECT ... FOR UPDATE (sin gaps por concurrencia).
 *
 * Esta clase no tiene factory propia: las filas las crea el servicio
 * lazy al pedir el primer folio para una combinación específica.
 *
 * @property int $id
 * @property int $company_id
 * @property int $branch_id
 * @property int $cash_register_id
 * @property string $series
 * @property int $current_value
 */
class SaleNumberCounter extends Model
{
    use BelongsToTenant;

    protected $table = 'sale_number_counters';

    protected $fillable = [
        'company_id', 'branch_id', 'cash_register_id',
        'series', 'current_value',
    ];

    protected $casts = [
        'current_value' => 'integer',
    ];
}
