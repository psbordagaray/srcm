<?php

namespace App\Enums;

enum InventoryNegativeRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Invalidated = 'invalidated';
    case Fulfilled = 'fulfilled';
}
