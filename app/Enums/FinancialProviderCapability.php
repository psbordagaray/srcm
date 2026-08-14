<?php

namespace App\Enums;

enum FinancialProviderCapability: string
{
    case Create = 'create';
    case Read = 'read';
    case Webhook = 'webhook';
    case Refund = 'refund';
    case Reconciliation = 'reconciliation';
}
