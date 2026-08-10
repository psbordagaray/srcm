<?php

namespace App\Enums;

enum FinancialMovementStatus: string
{
    case Pending = 'pending';
    case Posted = 'posted';
    case Reversed = 'reversed';
    case Failed = 'failed';
}
