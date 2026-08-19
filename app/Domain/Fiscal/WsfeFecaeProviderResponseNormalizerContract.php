<?php

namespace App\Domain\Fiscal;

interface WsfeFecaeProviderResponseNormalizerContract
{
    public function normalize(
        WsfeFecaeSoapResultData $result
    ): WsfeFecaeNormalizedResponseData;
}
