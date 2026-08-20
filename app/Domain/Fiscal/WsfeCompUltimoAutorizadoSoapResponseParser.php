<?php

namespace App\Domain\Fiscal;

interface WsfeCompUltimoAutorizadoSoapResponseParser
{
    public function parse(
        int $httpStatus,
        string $soapXml,
        FiscalRemoteSequenceQuery $query,
    ): FiscalRemoteSequenceState;
}
