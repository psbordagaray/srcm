<?php

namespace App\Domain\Fiscal;

use App\Enums\FiscalEnvironment;

final readonly class FiscalRemoteSequenceQuery
{
    public function __construct(
        public int $organizationId,
        public FiscalEnvironment $environment,
        public int $pointOfSaleNumber,
        public int $voucherTypeCode,
    ) {
    }
}
