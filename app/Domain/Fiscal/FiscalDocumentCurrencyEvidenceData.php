<?php

namespace App\Domain\Fiscal;

final readonly class FiscalDocumentCurrencyEvidenceData
{
    public function __construct(
        public int $fiscalDocumentId,
        public string $sourceCurrencyCode,
        public string $arcaCurrencyCode,
        public int $quotationMicros,
        public bool $sameCurrencySettlement,
    ) {
    }
}
