<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\Carbon;
use Database\Factories\SalePaymentFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property int $sale_id
 * @property string $method
 * @property float $amount
 * @property float|null $tendered_amount
 * @property string|null $reference
 * @property string|null $authorization_code
 * @property string|null $card_brand
 * @property string|null $card_last4
 * @property array<string, mixed> $metadata
 * @property Carbon $captured_at
 */
class SalePayment extends Model
{
    use BelongsToTenant;
    use HasFactory;

    public const METHOD_CASH = 'cash';

    public const METHOD_CARD_CREDIT = 'card_credit';

    public const METHOD_CARD_DEBIT = 'card_debit';

    public const METHOD_TRANSFER = 'transfer';

    public const METHOD_CHECK = 'check';

    public const METHOD_VOUCHER = 'voucher';

    public const METHOD_CREDIT = 'credit';

    public const METHOD_OTHER = 'other';

    /**
     * Métodos que afectan la caja física (efectivo entra/sale).
     *
     * @var array<int, string>
     */
    public const CASH_AFFECTING_METHODS = [
        self::METHOD_CASH,
    ];

    protected $table = 'sale_payments';

    protected $fillable = [
        'uuid', 'company_id', 'sale_id',
        'method', 'amount', 'tendered_amount',
        'reference', 'authorization_code', 'card_brand', 'card_last4',
        'metadata', 'captured_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'tendered_amount' => 'decimal:2',
        'metadata' => 'array',
        'captured_at' => 'datetime',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function isCash(): bool
    {
        return $this->method === self::METHOD_CASH;
    }

    public function isCredit(): bool
    {
        return $this->method === self::METHOD_CREDIT;
    }

    public function affectsCash(): bool
    {
        return in_array($this->method, self::CASH_AFFECTING_METHODS, true);
    }

    protected static function newFactory(): Factory
    {
        return SalePaymentFactory::new();
    }
}
