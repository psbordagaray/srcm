<?php

namespace App\Domain\Service;

final readonly class ServiceDiagnosticData
{
    /**
     * @param  list<ServiceDiagnosticFindingData>  $findings
     */
    public function __construct(
        public int $serviceOrderId,
        public string $summary,
        public string $recommendation,
        public array $findings,
        public string $idempotencyKey,
        public ?string $dataRiskNotes = null
    ) {}
}
