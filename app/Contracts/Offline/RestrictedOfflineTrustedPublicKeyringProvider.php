<?php

declare(strict_types=1);

namespace App\Contracts\Offline;

use App\Domain\Offline\RestrictedOfflineTrustedPublicKeyring;

interface RestrictedOfflineTrustedPublicKeyringProvider
{
    public function current(): RestrictedOfflineTrustedPublicKeyring;
}
