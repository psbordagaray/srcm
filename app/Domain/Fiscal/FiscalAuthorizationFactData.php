<?php

namespace App\Domain\Fiscal;

use App\Enums\FiscalAuthorizationOutcome;

final readonly class FiscalAuthorizationFactData
{
    public function __construct(
        public int $fiscalDocumentId,
        public FiscalAuthorizationOutcome $outcome,
        public ?string $resultCode,
        public string $idempotencyKey
    ) {}
}
