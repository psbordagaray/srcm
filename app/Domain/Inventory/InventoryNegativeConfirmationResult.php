<?php

namespace App\Domain\Inventory;

use App\Models\InventoryMovement;
use App\Models\InventoryNegativeIncident;
use App\Models\InventoryNegativeOverride;
use App\Models\InventoryNegativeRequest;

final readonly class InventoryNegativeConfirmationResult
{
    public function __construct(
        public InventoryMovement $movement,
        public InventoryNegativeRequest $request,
        public InventoryNegativeOverride $override,
        public ?InventoryNegativeIncident $incident,
        public bool $invalidated,
    ) {
    }
}
