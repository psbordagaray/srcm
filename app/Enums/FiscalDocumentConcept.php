<?php

namespace App\Enums;

enum FiscalDocumentConcept: string
{
    case Products = 'products';
    case Services = 'services';
    case ProductsAndServices = 'products_and_services';

    public function requiresServicePeriod(): bool
    {
        return $this !== self::Products;
    }
}
