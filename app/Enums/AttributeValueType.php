<?php

namespace App\Enums;

enum AttributeValueType: string
{
    case Text = 'text';
    case Boolean = 'boolean';
    case Integer = 'integer';
    case ExactDecimal = 'exact_decimal';
    case Measurement = 'measurement';
    case Date = 'date';
    case DateTime = 'datetime';
}
