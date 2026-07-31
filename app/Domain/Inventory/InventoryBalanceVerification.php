<?php

namespace App\Domain\Inventory;

final readonly class InventoryBalanceVerification
{
    /**
     * @param list<array{
     *     type: string,
     *     key: string,
     *     expected: array<string, mixed>|null,
     *     actual: array<string, mixed>|null
     * }> $differences
     */
    public function __construct(
        public int $organizationId,
        public array $differences
    ) {
    }

    public function isConsistent(): bool
    {
        return $this->differences === [];
    }

    public function differenceCount(): int
    {
        return count($this->differences);
    }
}
