<?php

namespace App\Domain\Service;

use App\Enums\InventoryCondition;
use App\Enums\ServicePartSource;

final readonly class ServicePartRequirementData
{
    public function __construct(
        public int $serviceWorkItemId,
        public int $serviceQuoteLineId,
        public int $catalogProductId,
        public InventoryCondition $condition,
        public ServicePartSource $source,
        public string $requiredQuantity,
        public string $idempotencyKey
    ) {}
}
