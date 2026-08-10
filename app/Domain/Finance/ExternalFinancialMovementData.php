<?php

namespace App\Domain\Finance;

use App\Enums\FinancialMovementDirection;
use App\Enums\FinancialMovementSource;
use App\Enums\FinancialMovementStatus;
use DateTimeInterface;

final readonly class ExternalFinancialMovementData
{
    public function __construct(
        public FinancialMovementSource $source,
        public string $sourceKey,
        public FinancialMovementDirection $direction,
        public FinancialMovementStatus $status,
        public string $currencyCode,
        public int $grossAmountMinor,
        public int $netAmountMinor,
        public int $feeAmountMinor = 0,
        public int $withholdingAmountMinor = 0,
        public ?string $externalOperationId = null,
        public ?string $rawReference = null,
        public ?DateTimeInterface $occurredAt = null
    ) {
    }
}
