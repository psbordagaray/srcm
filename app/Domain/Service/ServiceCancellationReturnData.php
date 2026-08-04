<?php

namespace App\Domain\Service;

use Carbon\CarbonImmutable;

final readonly class ServiceCancellationReturnData
{
    public function __construct(
        public int $serviceCancellationResolutionId,
        public string $recipientName,
        public string $conditionNotes,
        public string $accessoriesSnapshot,
        public string $idempotencyKey,
        public ?int $recipientBusinessPartyId = null,
        public ?string $recipientDocument = null,
        public ?string $notes = null,
        public ?CarbonImmutable $returnedAt = null
    ) {}
}
