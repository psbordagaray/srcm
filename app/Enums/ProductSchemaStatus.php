<?php

namespace App\Enums;

enum ProductSchemaStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Deprecated = 'deprecated';
    case Retired = 'retired';
}
