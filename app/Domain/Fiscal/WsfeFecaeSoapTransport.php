<?php

namespace App\Domain\Fiscal;

interface WsfeFecaeSoapTransport
{
    public function exchange(
        WsfeFecaeSoap11Call $call
    ): WsfeFecaeSoapResultData;
}
