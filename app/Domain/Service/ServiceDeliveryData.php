<?php

namespace App\Domain\Service;

use Carbon\CarbonImmutable;

final readonly class ServiceDeliveryData
{
    public function __construct(
        public int $serviceOrderId,
        public int $serviceQualityInspectionId,
        public string $recipientName,
        public string $conditionNotes,
        public string $accessoriesSnapshot,
        public bool $customerConformity,
        public string $idempotencyKey,
        public ?int $recipientBusinessPartyId = null,
        public ?string $recipientDocument = null,
        public ?string $notes = null,
        public ?CarbonImmutable $deliveredAt = null
    ) {}
}
