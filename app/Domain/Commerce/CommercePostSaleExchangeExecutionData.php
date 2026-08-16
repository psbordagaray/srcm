<?php

namespace App\Domain\Commerce;

final readonly class CommercePostSaleExchangeExecutionData
{
    /**
     * @param list<CommercePostSaleExchangeExecutionLineData> $lines
     * @param list<CommercePaymentData> $payments
     */
    public function __construct(
        public array $lines,
        public array $payments,
        public string $idempotencyKey,
        public ?string $notes = null
    ) {
    }
}
