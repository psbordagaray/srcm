<?php

namespace App\Domain\Fiscal;

final readonly class FiscalDocumentRecipientEvidenceData
{
    public function __construct(
        public int $fiscalDocumentId,
        public string $documentTypeCode,
        public string $documentNumber,
        public string $vatConditionCode,
    ) {
    }
}
