<?php

namespace App\Domain\Fiscal;

interface WsfeCompUltimoAutorizadoSoapTransport
{
    public function exchange(
        WsfeCompUltimoAutorizadoSoap11Call $call
    ): FiscalRemoteSequenceState;
}
