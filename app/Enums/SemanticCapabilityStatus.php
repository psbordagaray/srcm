<?php

namespace App\Enums;

enum SemanticCapabilityStatus: string
{
    case Active = 'active';
    case Deprecated = 'deprecated';
    case Retired = 'retired';
}
