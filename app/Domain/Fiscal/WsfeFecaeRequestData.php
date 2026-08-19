<?php

namespace App\Domain\Fiscal;

final readonly class WsfeFecaeRequestData
{
    public function __construct(
        public WsfeFecaeHeaderData $header,
        public WsfeFecaeDetailData $detail,
    ) {
    }

    /**
     * Canonical one-record FeCAEReq shape.
     * It intentionally excludes Auth, SOAP envelope and endpoint concerns.
     *
     * @return array{
     *   FeCabReq:array<string,mixed>,
     *   FeDetReq:array{FECAEDetRequest:list<array<string,mixed>>}
     * }
     */
    public function toWsfeArray(): array
    {
        return [
            'FeCabReq' => $this->header->toWsfeArray(),
            'FeDetReq' => [
                'FECAEDetRequest' => [
                    $this->detail->toWsfeArray(),
                ],
            ],
        ];
    }
}
