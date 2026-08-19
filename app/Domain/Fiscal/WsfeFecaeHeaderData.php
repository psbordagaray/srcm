<?php

namespace App\Domain\Fiscal;

final readonly class WsfeFecaeHeaderData
{
    public function __construct(
        public int $recordCount,
        public int $pointOfSaleNumber,
        public int $voucherTypeCode,
    ) {
    }

    /**
     * @return array{
     *   CantReg:int,
     *   PtoVta:int,
     *   CbteTipo:int
     * }
     */
    public function toWsfeArray(): array
    {
        return [
            'CantReg' => $this->recordCount,
            'PtoVta' => $this->pointOfSaleNumber,
            'CbteTipo' => $this->voucherTypeCode,
        ];
    }
}
