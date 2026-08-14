<?php

namespace App\Enums;

enum FinancialProviderCompatibilityStatus: string
{
    case Compatible = 'compatible';
    case Degraded = 'degraded';
    case MigrationRequired = 'migration_required';
    case Blocked = 'blocked';
    case Unknown = 'unknown';
}
