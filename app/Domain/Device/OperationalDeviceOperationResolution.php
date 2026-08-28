<?php

namespace App\Domain\Device;

use App\Models\OperationalDeviceOperationClaim;

final readonly class OperationalDeviceOperationResolution
{
    public function __construct(
        public OperationalDeviceOperationClaim $claim,
        public bool $replay,
    ) {
    }
}
