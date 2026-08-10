<?php

namespace App\Domain\Commerce;

use App\Enums\CommercePaymentMethod;
use DateTimeInterface;

final readonly class CommercePaymentData
{
    public function __construct(
        public CommercePaymentMethod $method,
        public int $amountMinor,
        public ?string $reference = null,
        public ?string $notes = null,
        public ?DateTimeInterface $paidAt = null,
        public ?string $cardBrand = null,
        public ?string $cardNetwork = null,
        public ?string $cardLast4 = null,
        public ?int $installments = null,
        public ?string $processor = null,
        public ?string $externalOperationId = null,
        public ?string $authorizationCode = null,
        public ?string $providerStatus = null
    ) {
    }
}
