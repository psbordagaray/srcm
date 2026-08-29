<?php

namespace App\Domain\Device;

use App\Enums\OperationalDeviceCapability;

final readonly class OperationalDeviceOperationData
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $clientOperationId,
        public OperationalDeviceCapability $capability,
        public string $operationType,
        public array $payload = [],
    ) {
    }
}
