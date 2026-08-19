<?php

namespace App\Domain\Fiscal;

interface WsaaCredentialMaterialValidator
{
    public function assertValid(
        string $certificatePem,
        string $privateKeyPem,
        ?string $privateKeyPassphrase
    ): void;
}
