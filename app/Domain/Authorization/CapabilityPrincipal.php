<?php

namespace App\Domain\Authorization;

enum CapabilityPrincipal: string
{
    case User = 'USER';
    case ExternalReviewer = 'EXTERNAL_REVIEWER';
    case Automation = 'AUTOMATION';
    case SystemOperator = 'SYSTEM_OPERATOR';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(
            static fn (self $principal): string => $principal->value,
            self::cases(),
        );
    }
}
