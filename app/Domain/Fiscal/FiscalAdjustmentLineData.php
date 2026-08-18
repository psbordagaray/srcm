<?php

namespace App\Domain\Fiscal;

use App\Enums\CommerceSaleLineType;

final readonly class FiscalAdjustmentLineData
{
    public function __construct(
        public int $position,
        public CommerceSaleLineType $lineType,
        public string $description,
        public string $quantity,
        public int $unitPriceMinor,
        public int $lineTotalMinor,
    ) {
    }
}
