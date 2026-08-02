<?php

namespace App\Domain\Service;

use App\Enums\ServiceAssetType;
use DateTimeInterface;

final readonly class ServiceOrderIntakeData
{
    /**
     * @param list<ServiceAssetIdentifierData> $identifiers
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public ServiceAssetType $assetType,
        public string $brandName,
        public string $modelName,
        public array $identifiers,
        public int $intakeLocationId,
        public string $customerReportedIssue,
        public string $idempotencyKey,
        public ?int $customerBusinessPartyId = null,
        public ?string $customerName = null,
        public ?int $ownerBusinessPartyId = null,
        public ?string $ownerName = null,
        public ?string $color = null,
        public ?string $intakeObservations = null,
        public ?string $receivedAccessories = null,
        public bool $contactAvailable = false,
        public ?string $contactReference = null,
        public ?DateTimeInterface $promisedAt = null,
        public array $metadata = []
    ) {}
}
