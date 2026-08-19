<?php

namespace App\Domain\Fiscal;

use App\Enums\FiscalEnvironment;

final readonly class FiscalRemoteSequenceState
{
    public function __construct(
        public FiscalEnvironment $environment,
        public int $pointOfSaleNumber,
        public int $voucherTypeCode,
        public int $lastAuthorizedNumber,
    ) {
    }
}
