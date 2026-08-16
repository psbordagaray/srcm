<?php

namespace App\Domain\Commerce;

final readonly class CommercePostSaleRequestLineData
{
    public function __construct(
        public int $commerceSaleLineId,
        public string $quantity
    ) {
    }
}
