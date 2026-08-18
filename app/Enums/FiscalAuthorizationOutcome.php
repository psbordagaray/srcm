<?php

namespace App\Enums;

enum FiscalAuthorizationOutcome: string
{
    case Authorized = 'authorized';
    case Rejected = 'rejected';
    case Unknown = 'unknown';
}
