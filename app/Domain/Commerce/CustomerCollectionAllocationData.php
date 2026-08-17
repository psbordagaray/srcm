<?php

namespace App\Domain\Commerce;

final readonly class CustomerCollectionAllocationData
{
    public function __construct(
        public int $customerReceivableId,
        public int $amountMinor
    ) {
    }
}
