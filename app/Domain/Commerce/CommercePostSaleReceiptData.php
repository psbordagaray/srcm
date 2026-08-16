<?php

namespace App\Domain\Commerce;

final readonly class CommercePostSaleReceiptData
{
    /**
     * @param list<CommercePostSaleReceiptLineData> $lines
     */
    public function __construct(
        public int $commercePostSaleRequestId,
        public array $lines,
        public string $idempotencyKey,
        public ?string $notes = null
    ) {
    }
}
