<?php

namespace App\Domain\Service;

final readonly class ServiceWorkCustodyData
{
    public function __construct(
        public int $serviceWorkItemId,
        public string $conditionNotes,
        public string $accessoriesSnapshot,
        public string $idempotencyKey
    ) {}
}
