<?php

namespace App\Domain\Authorization;

enum CapabilityScope: string
{
    case Organization = 'ORGANIZATION';
    case Installation = 'INSTALLATION';
    case Environment = 'ENVIRONMENT';
    case Release = 'RELEASE';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(
            static fn (self $scope): string => $scope->value,
            self::cases(),
        );
    }
}
