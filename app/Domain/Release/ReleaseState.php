<?php

namespace App\Domain\Release;

enum ReleaseState: string
{
    case Built = 'BUILT';
    case Verified = 'VERIFIED';
    case Authorized = 'AUTHORIZED';
    case InstalledInactive = 'INSTALLED_INACTIVE';
    case Ready = 'READY';
    case Active = 'ACTIVE';
    case Superseded = 'SUPERSEDED';
    case Retired = 'RETIRED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(
            static fn (self $case): string => $case->value,
            self::cases(),
        );
    }
}
