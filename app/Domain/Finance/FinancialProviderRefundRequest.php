<?php

namespace App\Domain\Finance;

final readonly class FinancialProviderRefundRequest
{
    public function __construct(
        public string $instructionPublicId,
        public string $originalExternalOperationId,
        public int $amountMinor,
        public string $currencyCode,
        public string $providerIdempotencyKey
    ) {
    }
}
