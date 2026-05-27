<?php

declare(strict_types=1);

namespace App\Domain\Sales\Dto;

/**
 * DTO inmutable que describe un item del carrito al hacer checkout.
 *
 * No depende de Eloquent: representa la "intención" del cliente.
 * El servicio lo resuelve a Product real, calcula precios y crea el SaleItem.
 */
final class CheckoutItem
{
    public function __construct(
        public readonly string $productUuid,
        public readonly float $quantity,
        public readonly ?float $unitPriceOverride = null,
        public readonly float $discountPercent = 0,
        public readonly ?float $discountAmountOverride = null,
        public readonly ?string $notes = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            productUuid: (string) $data['product_uuid'],
            quantity: (float) $data['quantity'],
            unitPriceOverride: isset($data['unit_price']) ? (float) $data['unit_price'] : null,
            discountPercent: (float) ($data['discount_percent'] ?? 0),
            discountAmountOverride: isset($data['discount_amount']) ? (float) $data['discount_amount'] : null,
            notes: $data['notes'] ?? null,
        );
    }
}
