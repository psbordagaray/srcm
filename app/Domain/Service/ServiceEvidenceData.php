<?php

namespace App\Domain\Service;

use App\Enums\ServiceEvidenceContext;
use DateTimeInterface;

final readonly class ServiceEvidenceData
{
    public function __construct(
        public int $serviceOrderId,
        public ServiceEvidenceContext $context,
        public string $sourcePath,
        public string $originalFilename,
        public string $idempotencyKey,
        public ?int $referenceId = null,
        public ?string $description = null,
        public ?DateTimeInterface $capturedAt = null
    ) {}
}
