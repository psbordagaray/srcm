<?php

namespace App\Enums;

enum SemanticProfileResolutionMode: string
{
    case CurrentPublished = 'current_published';
    case ExactHistorical = 'exact_historical';
}
