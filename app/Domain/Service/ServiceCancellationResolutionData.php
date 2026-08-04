<?php

namespace App\Domain\Service;

use App\Enums\ServiceCancellationFinancialOutcome;

final readonly class ServiceCancellationResolutionData
{
    public function __construct(
        public int $serviceCancellationRequestId,
        public ServiceCancellationFinancialOutcome $financialOutcome,
        public string $workDisposition,
        public string $partsDisposition,
        public string $financialDisposition,
        public string $returnConditionNotes,
        public string $accessoriesSnapshot,
        public string $idempotencyKey,
        public string $currencyCode = 'ARS',
        public int $customerChargeMinor = 0,
        public ?string $customerAcceptanceReference = null,
        public ?string $notes = null
    ) {}
}
