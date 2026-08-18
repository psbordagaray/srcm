<?php

namespace App\Domain\Fiscal;

use Carbon\CarbonImmutable;

final readonly class FiscalDocumentPaymentDueDateData
{
    public function __construct(
        public int $fiscalDocumentId,
        public CarbonImmutable $paymentDueDate,
    ) {
    }
}
