<?php

namespace App\Enums;

enum PaymentReconciliationStatus: string
{
    case PendingReview = 'pending_review';
    case Matched = 'matched';
    case Difference = 'difference';
    case Resolved = 'resolved';
}
