<?php

namespace App\Domain\Fiscal;

final readonly class FiscalTaxWsfeClassificationData
{
    /**
     * @param list<FiscalTaxWsfeIdentityData> $identities
     */
    public function __construct(
        public int $fiscalDocumentId,
        public array $identities,
    ) {
    }
}
