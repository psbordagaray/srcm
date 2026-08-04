<?php

namespace App\Domain\Service;

use DateTimeInterface;

final readonly class ServiceWarrantyClaimData
{
    public function __construct(
        public int $serviceWarrantyGrantId,
        public int $intakeLocationId,
        public string $claimantName,
        public string $reportedIssue,
        public string $reentryConditionNotes,
        public string $accessoriesSnapshot,
        public string $channel,
        public DateTimeInterface $claimedAt,
        public string $idempotencyKey,
        public ?int $claimantBusinessPartyId = null,
        public ?string $customerReference = null
    ) {}
}
