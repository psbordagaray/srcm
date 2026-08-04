<?php

namespace App\Domain\Service;

use App\Enums\ServiceWorkExecutionMode;

final readonly class ServiceWarrantyWorkItemData
{
    public function __construct(
        public int $serviceOrderId,
        public int $serviceWarrantyClaimResolutionId,
        public string $title,
        public string $description,
        public ServiceWorkExecutionMode $executionMode,
        public string $idempotencyKey,
        public ?int $providerBusinessPartyId = null,
        public ?int $assignedUserId = null
    ) {}
}
