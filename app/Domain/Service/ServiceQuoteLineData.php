<?php

namespace App\Domain\Service;

use App\Enums\ServiceQuoteLineType;

final readonly class ServiceQuoteLineData
{
    public function __construct(
        public ServiceQuoteLineType $type,
        public string $description,
        public string $quantity,
        public int $unitPriceMinor
    ) {}
}
