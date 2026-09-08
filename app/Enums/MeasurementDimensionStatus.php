<?php

namespace App\Enums;

enum MeasurementDimensionStatus: string
{
    case Active = 'active';
    case Deprecated = 'deprecated';
    case Retired = 'retired';
}
