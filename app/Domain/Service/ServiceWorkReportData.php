<?php

namespace App\Domain\Service;

use App\Enums\ServiceWorkOutcome;

final readonly class ServiceWorkReportData
{
    public function __construct(
        public int $serviceWorkItemId,
        public ServiceWorkOutcome $outcome,
        public string $resultSummary,
        public string $workPerformed,
        public string $idempotencyKey,
        public ?string $unresolvedReason = null,
        public ?int $warrantyDays = null,
        public ?string $warrantyTerms = null
    ) {}
}
