<?php

namespace App\Enums;

enum FinancialProviderConnectionHealthStatus: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Unavailable = 'unavailable';
    case Unknown = 'unknown';
}
