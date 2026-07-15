<?php

declare(strict_types=1);

namespace App\Domain\Sales\Dto;

/**
 * Datos completos de un checkout (venta a registrar).
 */
final class CheckoutRequest
{
    /**
     * @param  array<int, CheckoutItem>  $items
     * @param  array<int, CheckoutPayment>  $payments
     */
    public function __construct(
        public readonly string $cashSessionUuid,
        public readonly string $warehouseUuid,
        public readonly array $items,
        public readonly array $payments,
        public readonly ?string $customerUuid = null,
        public readonly ?string $customerName = null,
        public readonly ?string $customerTaxId = null,
        public readonly float $tipAmount = 0,
        public readonly ?string $notes = null,
        public readonly string $series = 'A',
        public readonly ?int $numberValue = null,
        public readonly ?string $deviceId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            cashSessionUuid: (string) $data['cash_session_uuid'],
            warehouseUuid: (string) $data['warehouse_uuid'],
            items: array_map(
                fn (array $i) => CheckoutItem::fromArray($i),
                $data['items'] ?? []
            ),
            payments: array_map(
                fn (array $p) => CheckoutPayment::fromArray($p),
                $data['payments'] ?? []
            ),
            customerUuid: $data['customer_uuid'] ?? null,
            customerName: $data['customer_name'] ?? null,
            customerTaxId: $data['customer_tax_id'] ?? null,
            tipAmount: (float) ($data['tip_amount'] ?? 0),
            notes: $data['notes'] ?? null,
            series: (string) ($data['series'] ?? 'A'),
            numberValue: isset($data['number_value']) ? (int) $data['number_value'] : null,
            deviceId: isset($data['device_id']) ? (string) $data['device_id'] : null,
        );
    }
}
