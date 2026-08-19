<?php

namespace App\Domain\Fiscal;

use Carbon\CarbonImmutable;

final class FiscalDocumentAssociationData
{
    /**
     * @param list<FiscalAssociatedVoucherData> $vouchers
     */
    public function __construct(
        public readonly int $fiscalDocumentId,
        public readonly string $mode,
        public readonly array $vouchers = [],
        public readonly ?CarbonImmutable $periodFrom = null,
        public readonly ?CarbonImmutable $periodTo = null,
    ) {
    }
}
