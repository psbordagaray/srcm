<?php

namespace App\Domain\Finance;

use Carbon\CarbonImmutable;

final readonly class FinancialReconciliationCandidate
{
    /**
     * @param list<string> $evidenceCodes
     */
    public function __construct(
        public int $movementId,
        public string $movementPublicId,
        public string $source,
        public string $sourceKey,
        public ?string $externalOperationId,
        public int $grossAmountMinor,
        public int $netAmountMinor,
        public int $feeAmountMinor,
        public int $withholdingAmountMinor,
        public int $grossDifferenceMinor,
        public CarbonImmutable $occurredAt,
        public int $distanceSeconds,
        public array $evidenceCodes,
        public int $orderingScore,
        public string $evidenceLevel
    ) {
    }
}
