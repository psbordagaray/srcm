<?php

namespace App\Domain\Commerce;

use App\Enums\CommercePaymentMethod;

final readonly class CustomerCollectionData
{
    /**
     * @param list<CustomerCollectionAllocationData> $allocations
     */
    public function __construct(
        public string $currencyCode,
        public CommercePaymentMethod $method,
        public int $amountMinor,
        public int $financialAccountId,
        public array $allocations,
        public string $idempotencyKey,
        public ?string $reference = null,
        public ?int $tenderedAmountMinor = null,
        public ?string $notes = null,
        public bool $retainExcessAsCredit = false
    ) {
    }
}
