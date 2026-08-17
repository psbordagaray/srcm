<?php

namespace App\Domain\Purchase;

final readonly class SupplierAdvanceExecutionData
{
    public function __construct(
        public string $idempotencyKey,
        public ?string $executionReference = null,
        public ?string $executionNote = null
    ) {
    }
}
