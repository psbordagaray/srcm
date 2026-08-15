<?php

namespace App\Domain\Finance;

use App\Enums\FinancialMovementDirection;
use Carbon\CarbonImmutable;

final readonly class FinancialStatementImportPreviewRow
{
    public function __construct(
        public int $lineNumber,
        public string $sourceKey,
        public string $fingerprint,
        public CarbonImmutable $occurredAt,
        public FinancialMovementDirection $direction,
        public string $currencyCode,
        public int $grossAmountMinor,
        public int $feeAmountMinor,
        public int $withholdingAmountMinor,
        public int $netAmountMinor,
        public ?string $externalOperationId,
        public ?string $reference
    ) {
    }
}
