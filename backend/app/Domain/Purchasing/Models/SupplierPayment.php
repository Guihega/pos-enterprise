<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Models;

use App\Domain\Identity\Models\User;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Pago a proveedor (4.1.5, maestro 29.7).
 *
 * SIN SoftDeletes por decision de alcance: un movimiento de dinero no se
 * borra, se contra-registra. Con borrado logico un pago invisible al global
 * scope descuadraria paid_amount de la factura. Sigue el patron de linea hija
 * del repo (leccion 14): extends Model + BelongsToTenant.
 *
 * COROLARIO: el folio consecutivo NO puede usar withTrashed() (el metodo no
 * existe sin SoftDeletes) y tampoco lo necesita: sin borrado logico max(id) no
 * recicla consecutivos.
 *
 * A diferencia de PurchaseOrderItem, SI tiene uuid: es entidad de ruta
 * potencial y el maestro se lo define.
 *
 * invoice_id es nullable porque el maestro admite pago a cuenta sin factura.
 * DIFERIDO: como se aplica ese pago al saldo del proveedor no esta definido y
 * no lo resuelve esta entrega.
 *
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property int $supplier_id
 * @property int|null $invoice_id
 * @property string $folio
 * @property Carbon $payment_date
 * @property float $amount
 * @property string $method
 * @property string|null $reference
 * @property int|null $user_id
 * @property string|null $notes
 */
class SupplierPayment extends Model
{
    use BelongsToTenant;

    protected $table = 'supplier_payments';

    protected $fillable = [
        'uuid',
        'company_id',
        'supplier_id',
        'invoice_id',
        'folio',
        'payment_date',
        'amount',
        'method',
        'reference',
        'user_id',
        'notes',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount' => 'float',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class, 'invoice_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
