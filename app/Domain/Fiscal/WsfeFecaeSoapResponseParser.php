<?php

namespace App\Domain\Fiscal;

interface WsfeFecaeSoapResponseParser
{
    public function parse(
        int $httpStatus,
        string $soapXml
    ): WsfeFecaeSoapResultData;
}
