<?php

namespace App\Domain\Fiscal;

use App\Enums\FiscalEnvironment;

final readonly class FiscalAuthorizationTransportRequest
{
    public function __construct(
        public int $organizationId,
        public int $fiscalDocumentId,
        public FiscalEnvironment $environment,
        public int $pointOfSaleNumber,
        public int $voucherTypeCode,
        public int $voucherNumber,
        public WsfeFecaeRequestData $fecaeRequest,
    ) {
    }
}
