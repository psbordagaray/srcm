<?php

namespace App\Domain\Inventory;

use App\Models\InventoryMovement;

final readonly class InventoryMovementCorrection
{
    public function __construct(
        public InventoryMovement $original,
        public InventoryMovement $reversal,
        public InventoryMovement $replacement
    ) {
    }
}
