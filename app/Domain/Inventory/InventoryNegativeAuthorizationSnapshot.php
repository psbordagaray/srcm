<?php

namespace App\Domain\Inventory;

final readonly class InventoryNegativeAuthorizationSnapshot
{
    /**
     * @param list<InventoryNegativePositionSnapshot> $positions
     */
    public function __construct(
        public string $movementFingerprint,
        public string $snapshotFingerprint,
        public array $positions,
    ) {
    }

    public function requiresOverride(): bool
    {
        foreach ($this->positions as $position) {
            if ($position->createsNegative) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, InventoryNegativePositionSnapshot>
     */
    public function positionsByKey(int $organizationId): array
    {
        $indexed = [];

        foreach ($this->positions as $position) {
            $indexed[implode(':', [
                $organizationId,
                $position->catalogProductId,
                $position->inventoryLocationId,
                $position->condition->value,
            ])] = $position;
        }

        return $indexed;
    }
}
