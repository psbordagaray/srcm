<?php

namespace App\Domain\Service;

final readonly class ServiceQualityCheckData
{
    public function __construct(
        public string $code,
        public string $label,
        public bool $passed,
        public ?string $notes = null
    ) {}
}
