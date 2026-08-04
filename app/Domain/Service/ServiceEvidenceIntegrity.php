<?php

namespace App\Domain\Service;

final readonly class ServiceEvidenceIntegrity
{
    public function __construct(
        public bool $exists,
        public bool $sizeMatches,
        public bool $hashMatches,
        public ?int $observedSizeBytes = null,
        public ?string $observedSha256 = null
    ) {}

    public function valid(): bool
    {
        return $this->exists
            && $this->sizeMatches
            && $this->hashMatches;
    }
}
