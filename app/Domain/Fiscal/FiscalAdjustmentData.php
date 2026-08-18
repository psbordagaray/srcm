<?php

namespace App\Domain\Fiscal;

use App\Enums\FiscalDocumentType;

final readonly class FiscalAdjustmentData
{
    /**
     * @param array<string,mixed> $recipientSnapshot
     * @param list<FiscalAdjustmentLineData> $lines
     */
    public function __construct(
        public int $fiscalPointOfSaleId,
        public FiscalDocumentType $documentType,
        public array $recipientSnapshot,
        public string $currencyCode,
        public int $serviceSubtotalMinor,
        public int $productSubtotalMinor,
        public int $totalMinor,
        public array $lines,
        public string $idempotencyKey,
    ) {
    }
}
