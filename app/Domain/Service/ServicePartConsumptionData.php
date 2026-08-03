<?php

namespace App\Domain\Service;

final readonly class ServicePartConsumptionData
{
    public function __construct(
        public int $servicePartRequirementId,
        public string $quantity,
        public string $idempotencyKey,
        public ?int $sourceLocationId = null,
        public ?int $servicePartPurchaseLineId = null
    ) {}
}
