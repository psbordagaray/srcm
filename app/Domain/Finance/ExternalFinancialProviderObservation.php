<?php

namespace App\Domain\Finance;

use App\Enums\FinancialMovementDirection;
use App\Enums\FinancialMovementStatus;
use DateTimeInterface;

final readonly class ExternalFinancialProviderObservation
{
    public function __construct(
        public string $providerKey,
        public string $observationKey,
        public string $externalOperationId,
        public FinancialMovementDirection $direction,
        public FinancialMovementStatus $status,
        public string $currencyCode,
        public int $grossAmountMinor,
        public int $netAmountMinor,
        public int $feeAmountMinor = 0,
        public int $withholdingAmountMinor = 0,
        public ?string $rawReference = null,
        public ?DateTimeInterface $occurredAt = null
    ) {
    }
}
