<?php

namespace App\Enums;

enum InventoryNegativeIncidentStatus: string
{
    case Open = 'open';
    case UnderReview = 'under_review';
    case Resolved = 'resolved';
}
