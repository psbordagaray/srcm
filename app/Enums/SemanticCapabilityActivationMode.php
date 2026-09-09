<?php

namespace App\Enums;

enum SemanticCapabilityActivationMode: string
{
    case FixedEnabled = 'fixed_enabled';
    case Configurable = 'configurable';
}
