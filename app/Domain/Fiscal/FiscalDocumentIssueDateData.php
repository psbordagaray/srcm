<?php

namespace App\Domain\Fiscal;

use Carbon\CarbonImmutable;

final readonly class FiscalDocumentIssueDateData
{
    public function __construct(
        public int $fiscalDocumentId,
        public CarbonImmutable $issueDate,
    ) {
    }
}
