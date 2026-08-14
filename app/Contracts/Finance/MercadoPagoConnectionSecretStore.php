<?php

namespace App\Contracts\Finance;

use App\Adapters\Finance\MercadoPago\MercadoPagoConnectionSecrets;
use App\Models\FinancialProviderConnection;

interface MercadoPagoConnectionSecretStore
{
    public function forConnection(
        FinancialProviderConnection $connection
    ): MercadoPagoConnectionSecrets;
}
