<?php

namespace App\Enums;

enum FinancialMovementSource: string
{
    case Api = 'api';
    case Webhook = 'webhook';
    case Polling = 'polling';
    case Csv = 'csv';
    case Xlsx = 'xlsx';
    case Manual = 'manual';
}
