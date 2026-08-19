<?php

namespace App\Domain\Fiscal;

use Carbon\CarbonImmutable;

final class FiscalAssociatedVoucherData
{
    public function __construct(
        public readonly int $voucherTypeCode,
        public readonly int $pointOfSaleNumber,
        public readonly int $voucherNumber,
        public readonly ?string $issuerCuit = null,
        public readonly ?CarbonImmutable $voucherDate = null,
    ) {
    }
}
