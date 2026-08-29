<?php

namespace App\Domain\Device;

use App\Models\OperationalDeviceBrowserBinding;

final readonly class OperationalDeviceBrowserBindingIssue
{
    public function __construct(
        public OperationalDeviceBrowserBinding $binding,
        public string $token,
    ) {
    }
}
