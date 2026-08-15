<?php

namespace App\Domain\Finance;

use App\Enums\FinancialMovementDirection;
use Carbon\CarbonImmutable;

final readonly class FinancialManualExternalMovementData
{
    public function __construct(
        public FinancialMovementDirection $direction,
        public int $grossAmountMinor,
        public int $feeAmountMinor,
        public int $withholdingAmountMinor,
        public int $netAmountMinor,
        public CarbonImmutable $occurredAt,
        public ?string $externalOperationId,
        public ?string $reference,
        public string $reason,
        public string $idempotencyKey
    ) {
    }
}
