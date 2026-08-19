<?php

namespace App\Domain\Fiscal;

use App\Enums\WsaaCmsDigestAlgorithm;

interface WsaaCmsSigner
{
    public function sign(
        WsaaTra $tra,
        WsaaCredentialMaterial $material,
        WsaaCmsDigestAlgorithm $digest
    ): WsaaSignedCms;
}
