<?php

namespace App\Enums;

enum InventoryTransformationOutputRole: string
{
    case Primary = 'primary';
    case CoProduct = 'co_product';
    case Byproduct = 'byproduct';
    case Recovered = 'recovered';
    case Scrap = 'scrap';
}
