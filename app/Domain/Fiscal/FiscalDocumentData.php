<?php

namespace App\Domain\Fiscal;

use App\Enums\FiscalDocumentType;

final readonly class FiscalDocumentData
{
    public function __construct(
        public int $commerceSaleId,
        public int $fiscalPointOfSaleId,
        public FiscalDocumentType $documentType,
        public string $idempotencyKey
    ) {
    }
}
