<?php

namespace App\Domain\Release;

enum MigrationCompatibility: string
{
    case NoSchemaChange = 'NO_SCHEMA_CHANGE';
    case BackwardCompatible = 'BACKWARD_COMPATIBLE';
    case MaintenanceRequired = 'MAINTENANCE_REQUIRED';
    case Breaking = 'BREAKING';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(
            static fn (self $case): string => $case->value,
            self::cases(),
        );
    }
}
