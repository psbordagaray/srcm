<?php

namespace App\Enums;

enum AttributeDefinitionStatus: string
{
    case Active = 'active';
    case Deprecated = 'deprecated';
    case Retired = 'retired';
}
