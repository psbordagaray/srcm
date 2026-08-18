<?php

namespace App\Domain\Fiscal;

use App\Enums\FiscalDocumentConcept;
use Carbon\CarbonImmutable;

final readonly class FiscalDocumentConceptData
{
    public function __construct(
        public int $fiscalDocumentId,
        public FiscalDocumentConcept $concept,
        public ?CarbonImmutable $servicePeriodFrom,
        public ?CarbonImmutable $servicePeriodTo,
    ) {
    }
}
