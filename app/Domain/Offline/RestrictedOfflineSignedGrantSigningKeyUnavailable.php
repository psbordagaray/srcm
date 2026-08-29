<?php

declare(strict_types=1);

namespace App\Domain\Offline;

use RuntimeException;

final class RestrictedOfflineSignedGrantSigningKeyUnavailable extends RuntimeException
{
}
