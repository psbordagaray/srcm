<?php

namespace App\Domain\Service;

use App\Enums\ServiceWarrantyClaimOutcome;

final readonly class ServiceWarrantyClaimResolutionData
{
    public function __construct(
        public int $serviceWarrantyClaimId,
        public ServiceWarrantyClaimOutcome $outcome,
        public string $technicalBasis,
        public string $idempotencyKey,
        public ?string $coveredScope = null,
        public ?string $excludedScope = null,
        public ?string $exceptionReason = null,
        public ?string $notes = null
    ) {}
}
