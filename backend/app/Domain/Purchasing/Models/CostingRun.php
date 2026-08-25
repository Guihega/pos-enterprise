<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Models;

use App\Domain\Identity\Models\User;
use App\Models\TenantScopedModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Corrida de costeo (docs/DISENO_COSTEO.md).
 *
 * Agrupa las compras de UN viaje: freight_total y other_costs_total son
 * del viaje y el servicio los prorratea entre lineas por valor (D4).
 *
 * status y confirmed_at NO son fillable, mismo argumento que paid_amount
 * en SupplierInvoice: los mueve el servicio en confirm() por asignacion
 * directa; dejarlos asignables permitiria crear por POST una corrida que
 * nace confirmada sin haber escrito costos.
 *
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property string $name
 * @property string $status
 * @property float $freight_total
 * @property float $other_costs_total
 * @property string|null $notes
 * @property int|null $created_by
 * @property Carbon|null $confirmed_at
 */
class CostingRun extends TenantScopedModel
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_CONFIRMED = 'confirmed';

    protected $table = 'costing_runs';

    protected $fillable = [
        'uuid',
        'company_id',
        'name',
        'freight_total',
        'other_costs_total',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'freight_total' => 'float',
        'other_costs_total' => 'float',
        'confirmed_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(CostingRunLine::class, 'costing_run_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
