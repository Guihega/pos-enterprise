<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Models;

use App\Models\TenantScopedModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Factura de proveedor (4.1.5, maestro 29.7).
 *
 * El folio lo emite el PROVEEDOR: se captura, no se genera. El unique es
 * (company_id, supplier_id, folio) porque dos proveedores pueden emitir el
 * mismo numero.
 *
 * paid_amount y status NO son fillable, a diferencia de PurchaseOrder que si
 * incluye status. La divergencia es deliberada: el estado de una OC lo mueven
 * actos explicitos del usuario (submit, approve), mientras que el de la
 * factura es funcion pura de paid_amount y lo deriva SIEMPRE el servicio al
 * registrar un pago. Dejarlos asignables permitiria crear por POST una factura
 * que nace pagada sin un solo pago detras. El servicio los escribe por
 * asignacion directa, que no pasa por fillable.
 *
 * balance NO es columna (ver migracion 000050): se calcula al vuelo como
 * total - paid_amount.
 *
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property int $supplier_id
 * @property int|null $purchase_order_id
 * @property string $folio
 * @property string|null $cfdi_uuid
 * @property string|null $cfdi_xml_url
 * @property Carbon $issue_date
 * @property Carbon $due_date
 * @property float $subtotal
 * @property float $tax_total
 * @property float $total
 * @property float $paid_amount
 * @property string $status
 * @property string|null $payment_method
 * @property string|null $notes
 */
class SupplierInvoice extends TenantScopedModel
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'supplier_invoices';

    protected $fillable = [
        'uuid',
        'company_id',
        'supplier_id',
        'purchase_order_id',
        'folio',
        'cfdi_uuid',
        'cfdi_xml_url',
        'issue_date',
        'due_date',
        'subtotal',
        'tax_total',
        'total',
        'payment_method',
        'notes',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'due_date' => 'date',
        'subtotal' => 'float',
        'tax_total' => 'float',
        'total' => 'float',
        'paid_amount' => 'float',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class, 'invoice_id');
    }

    /**
     * Saldo pendiente. No es columna: el maestro lo define como generada
     * (total - paid_amount) STORED, pero ninguna migracion del repo usa
     * storedAs. paid_amount es la unica fuente de verdad.
     */
    public function balance(): float
    {
        return round($this->total - $this->paid_amount, 2);
    }

    public function scopeOfStatus(Builder $q, string $status): Builder
    {
        return $q->where('status', $status);
    }
}
