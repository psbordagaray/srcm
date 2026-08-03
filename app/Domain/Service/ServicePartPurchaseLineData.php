<?php

namespace App\Domain\Service;

final readonly class ServicePartPurchaseLineData
{
    public function __construct(
        public int $servicePartRequirementId,
        public string $quantity,
        public int $unitCostMinor
    ) {}
}
