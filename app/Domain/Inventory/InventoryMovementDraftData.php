<?php

namespace App\Domain\Inventory;

use App\Enums\InventoryMovementType;
use DateTimeInterface;

final readonly class InventoryMovementDraftData
{
    /**
     * @param list<InventoryMovementLineData> $lines
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public InventoryMovementType $type,
        public DateTimeInterface $effectiveAt,
        public string $reason,
        public string $idempotencyKey,
        public array $lines,
        public ?string $sourceType = null,
        public ?string $sourceId = null,
        public ?string $sourceReference = null,
        public array $metadata = []
    ) {
    }
}
