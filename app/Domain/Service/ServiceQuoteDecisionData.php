<?php

namespace App\Domain\Service;

use App\Enums\ServiceQuoteDecisionType;

final readonly class ServiceQuoteDecisionData
{
    public function __construct(
        public int $serviceQuoteId,
        public ServiceQuoteDecisionType $decision,
        public string $customerName,
        public string $channel,
        public string $idempotencyKey,
        public ?int $serviceQuoteOptionId = null,
        public ?string $customerReference = null,
        public ?string $reason = null
    ) {}
}
