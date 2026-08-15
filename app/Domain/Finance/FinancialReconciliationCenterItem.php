<?php

namespace App\Domain\Finance;

use Carbon\CarbonImmutable;

final readonly class FinancialReconciliationCenterItem
{
    /**
     * @param list<FinancialReconciliationCandidate> $candidates
     */
    public function __construct(
        public int $paymentId,
        public string $salePublicId,
        public string $accountName,
        public string $accountType,
        public string $currencyCode,
        public string $paymentMethod,
        public int $expectedGrossAmountMinor,
        public ?string $declaredExternalOperationId,
        public CarbonImmutable $paidAt,
        public string $reconciliationStatus,
        public ?int $latestAllocatedGrossAmountMinor,
        public ?int $latestDifferenceMinor,
        public array $candidates
    ) {
    }
}
