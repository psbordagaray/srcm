<?php

namespace App\Domain\Commerce;

use DateTimeInterface;

final readonly class CommerceCheckoutData
{
    /**
     * @param list<CommerceProductLineData> $productLines
     * @param list<CommercePaymentData> $payments
     */
    public function __construct(
        public string $currencyCode,
        public string $idempotencyKey,
        public array $payments,
        public ?int $receivableAmountMinor = null,
        public ?DateTimeInterface $receivableDueOn = null,
        public array $productLines = [],
        public ?int $serviceOrderId = null,
        public ?int $customerBusinessPartyId = null,
        public ?string $customerName = null,
        public ?string $customerDocument = null,
        public ?string $notes = null,
        public ?DateTimeInterface $soldAt = null,
        public ?string $customerCreditOverrideReason = null,
        public ?int $receivableInstallmentCount = null
    ) {
    }
}
