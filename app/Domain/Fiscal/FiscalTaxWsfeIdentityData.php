<?php

namespace App\Domain\Fiscal;

use App\Enums\FiscalTaxWsfeBucket;

final readonly class FiscalTaxWsfeIdentityData
{
    public function __construct(
        public int $fiscalDocumentTaxId,
        public FiscalTaxWsfeBucket $bucket,
        public int $arcaId,
        public ?string $tributeDescription = null,
    ) {
    }
}
