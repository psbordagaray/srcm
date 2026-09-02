<?php

namespace App\Domain\Authorization;

enum CapabilityDecision: string
{
    case Allow = 'ALLOW';
    case Deny = 'DENY';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(
            static fn (self $decision): string => $decision->value,
            self::cases(),
        );
    }
}
