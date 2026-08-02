<?php

namespace App\Domain\Service;

use App\Enums\ServiceFindingSeverity;

final readonly class ServiceDiagnosticFindingData
{
    public function __construct(
        public ServiceFindingSeverity $severity,
        public string $category,
        public string $description,
        public ?string $evidenceNotes = null
    ) {}
}
