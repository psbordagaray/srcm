<?php

namespace App\Domain\Commerce;

use App\Enums\CommercePostSaleIntent;

final readonly class CommercePostSaleRequestData
{
    /**
     * @param list<CommercePostSaleRequestLineData> $lines
     */
    public function __construct(
        public int $commerceSaleId,
        public CommercePostSaleIntent $intent,
        public array $lines,
        public string $reason,
        public string $idempotencyKey,
        public ?string $notes = null
    ) {
    }
}
