<?php

namespace App\Domain\Service;

use App\Enums\ServiceIdentifierType;

final readonly class ServiceAssetIdentifierData
{
    public function __construct(
        public ServiceIdentifierType $type,
        public string $value
    ) {}
}
