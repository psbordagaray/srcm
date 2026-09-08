<?php

namespace App\Enums;

enum MeasurementUnitStatus: string
{
    case Active = 'active';
    case Deprecated = 'deprecated';
    case Retired = 'retired';
}
