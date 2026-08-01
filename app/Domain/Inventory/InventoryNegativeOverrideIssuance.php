<?php

namespace App\Domain\Inventory;

use App\Models\InventoryNegativeOverride;
use App\Models\InventoryNegativeRequest;

final readonly class InventoryNegativeOverrideIssuance
{
    public function __construct(
        public InventoryNegativeRequest $request,
        public ?InventoryNegativeOverride $override,
        public bool $invalidated,
    ) {
    }
}
