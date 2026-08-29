<?php

declare(strict_types=1);

namespace App\Contracts\Offline;

use App\Domain\Offline\RestrictedOfflineSignedGrantSigningKey;

interface RestrictedOfflineSignedGrantSigningKeyProvider
{
    public function current(): RestrictedOfflineSignedGrantSigningKey;
}
