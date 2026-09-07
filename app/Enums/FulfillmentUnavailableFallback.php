<?php

namespace App\Enums;

enum FulfillmentUnavailableFallback: string
{
    case KeepPending = 'keep_pending';
    case RemoveLine = 'remove_line';
    case ConsultCustomer = 'consult_customer';
}
