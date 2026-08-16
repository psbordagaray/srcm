<?php

namespace App\Domain\Commerce;

use App\Enums\CommercePostSaleResolutionOutcome;

final readonly class CommercePostSaleResolutionData
{
    /**
     * @param list<CommercePostSaleResolutionLineData> $lines
     */
    public function __construct(
        public int $commercePostSaleRequestId,
        public CommercePostSaleResolutionOutcome $outcome,
        public array $lines,
        public string $reason,
        public string $idempotencyKey,
        public ?int $preferredOriginalPaymentId = null,
        public ?string $notes = null
    ) {
    }
}
