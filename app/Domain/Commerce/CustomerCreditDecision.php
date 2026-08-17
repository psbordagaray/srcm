<?php

namespace App\Domain\Commerce;

use App\Enums\CustomerCreditDecisionType;
use App\Models\CustomerCreditPolicy;

final readonly class CustomerCreditDecision
{
    public function __construct(
        public CustomerCreditDecisionType $type,
        public ?CustomerCreditPolicy $policy,
        public ?int $limitMinor,
        public int $exposureBeforeMinor,
        public int $projectedExposureMinor,
        public int $overdueMinor,
        public int $oldestDaysOverdue,
        public bool $overLimit,
        public bool $overdue,
        public string $snapshotFingerprint,
        public ?string $overrideReason = null
    ) {
    }

    public function requiresOverrideRecord(): bool
    {
        return $this->type
            === CustomerCreditDecisionType::AdminOverride;
    }
}
