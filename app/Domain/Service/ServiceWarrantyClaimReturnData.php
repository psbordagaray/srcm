<?php

namespace App\Domain\Service;

use DateTimeInterface;

final readonly class ServiceWarrantyClaimReturnData
{
    public function __construct(
        public int $serviceWarrantyClaimId,
        public string $recipientName,
        public string $conditionNotes,
        public string $accessoriesSnapshot,
        public string $idempotencyKey,
        public ?int $recipientBusinessPartyId = null,
        public ?string $recipientDocument = null,
        public ?string $notes = null,
        public ?DateTimeInterface $returnedAt = null
    ) {}
}
