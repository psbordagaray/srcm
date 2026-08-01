<?php

namespace App\Enums;

enum InventoryNegativeOverrideStatus: string
{
    case Active = 'active';
    case Consumed = 'consumed';
    case Revoked = 'revoked';
    case Invalidated = 'invalidated';
}
