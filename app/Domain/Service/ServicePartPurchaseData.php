<?php

namespace App\Domain\Service;

use DateTimeInterface;

final readonly class ServicePartPurchaseData
{
    /** @param list<ServicePartPurchaseLineData> $lines */
    public function __construct(
        public int $serviceOrderId,
        public int $supplierId,
        public string $currencyCode,
        public DateTimeInterface $purchasedAt,
        public array $lines,
        public string $idempotencyKey,
        public int $logisticsCostMinor = 0,
        public ?string $documentReference = null,
        public ?string $notes = null
    ) {}
}
