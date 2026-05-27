<?php

declare(strict_types=1);

namespace App\Domain\Sales\Dto;

final class CheckoutPayment
{
    public function __construct(
        public readonly string $method,
        public readonly float $amount,
        public readonly ?float $tenderedAmount = null,
        public readonly ?string $reference = null,
        public readonly ?string $authorizationCode = null,
        public readonly ?string $cardBrand = null,
        public readonly ?string $cardLast4 = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            method: (string) $data['method'],
            amount: (float) $data['amount'],
            tenderedAmount: isset($data['tendered_amount']) ? (float) $data['tendered_amount'] : null,
            reference: $data['reference'] ?? null,
            authorizationCode: $data['authorization_code'] ?? null,
            cardBrand: $data['card_brand'] ?? null,
            cardLast4: $data['card_last4'] ?? null,
        );
    }
}
