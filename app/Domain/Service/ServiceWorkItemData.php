<?php

namespace App\Domain\Service;

use App\Enums\ServiceWorkExecutionMode;

final readonly class ServiceWorkItemData
{
    public function __construct(
        public int $serviceOrderId,
        public int $serviceQuoteOptionId,
        public string $title,
        public string $description,
        public ServiceWorkExecutionMode $executionMode,
        public string $idempotencyKey,
        public ?int $providerBusinessPartyId = null,
        public ?int $assignedUserId = null
    ) {}
}
