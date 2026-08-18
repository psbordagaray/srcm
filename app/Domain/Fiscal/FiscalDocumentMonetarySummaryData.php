<?php

namespace App\Domain\Fiscal;

final readonly class FiscalDocumentMonetarySummaryData
{
    public function __construct(
        public int $fiscalDocumentId,
        public int $nonTaxedAmountMinor,
        public int $netTaxableAmountMinor,
        public int $exemptAmountMinor,
        public int $tributesAmountMinor,
        public int $vatAmountMinor,
    ) {
    }
}
