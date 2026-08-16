<?php

namespace App\Domain\Commerce;

final readonly class CommercePostSaleExchangeSelectionLineData
{
    public function __construct(
        public int $catalogProductId,
        public string $quantity
    ) {
    }
}
