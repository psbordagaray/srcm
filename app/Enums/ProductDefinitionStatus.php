<?php

namespace App\Enums;

enum ProductDefinitionStatus: string
{
    case Active = 'active';
    case Deprecated = 'deprecated';
    case Retired = 'retired';
}
