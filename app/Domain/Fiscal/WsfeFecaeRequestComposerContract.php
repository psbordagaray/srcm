<?php

namespace App\Domain\Fiscal;

use App\Models\FiscalDocument;

interface WsfeFecaeRequestComposerContract
{
    public function compose(
        FiscalDocument $document,
        int $voucherNumber
    ): WsfeFecaeRequestData;
}
