<?php

namespace App\Domain\Release;

enum MigrationRiskClass: string
{
    case None = 'NONE';
    case Low = 'LOW';
    case Medium = 'MEDIUM';
    case High = 'HIGH';
    case Critical = 'CRITICAL';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(
            static fn (self $case): string => $case->value,
            self::cases(),
        );
    }
}
