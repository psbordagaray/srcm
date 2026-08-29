<?php

declare(strict_types=1);

namespace App\Contracts\Offline;

use App\Domain\Offline\RestrictedOfflineSignedGrantCredentialMaterial;
use Laravel\Passkeys\Passkey;

interface RestrictedOfflineSignedGrantCredentialMaterialExtractor
{
    public function extract(
        Passkey $passkey
    ): RestrictedOfflineSignedGrantCredentialMaterial;
}
