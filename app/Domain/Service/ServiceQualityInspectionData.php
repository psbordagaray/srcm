<?php

namespace App\Domain\Service;

final readonly class ServiceQualityInspectionData
{
    /** @param list<ServiceQualityCheckData> $checks */
    public function __construct(
        public int $serviceOrderId,
        public array $checks,
        public string $conditionNotes,
        public string $accessoriesSnapshot,
        public string $idempotencyKey,
        public ?string $reworkReason = null,
        public ?string $notes = null
    ) {}
}
