<?php

namespace App\Domain\Commerce;

final readonly class CommercePostSaleExchangeSelectionData
{
    /**
     * @param list<CommercePostSaleExchangeSelectionLineData> $lines
     */
    public function __construct(
        public array $lines,
        public string $idempotencyKey,
        public ?string $notes = null
    ) {
    }
}
