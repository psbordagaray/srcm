<?php

namespace App\Domain\Service;

use App\Enums\ServiceCancellationReason;

final readonly class ServiceCancellationRequestData
{
    public function __construct(
        public int $serviceOrderId,
        public ServiceCancellationReason $reason,
        public string $requesterName,
        public string $channel,
        public string $idempotencyKey,
        public ?int $requesterBusinessPartyId = null,
        public ?string $customerReference = null,
        public ?string $details = null
    ) {}
}
