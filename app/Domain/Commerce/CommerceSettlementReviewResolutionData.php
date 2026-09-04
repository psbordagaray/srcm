<?php

namespace App\Domain\Commerce;

use App\Enums\CommerceSettlementReviewResolutionOutcome;

final readonly class CommerceSettlementReviewResolutionData
{
    public function __construct(
        public int $commerceSettlementReviewId,
        public CommerceSettlementReviewResolutionOutcome $outcome,
        public string $reason,
        public ?string $notes,
        public string $idempotencyKey,
    ) {
    }
}
