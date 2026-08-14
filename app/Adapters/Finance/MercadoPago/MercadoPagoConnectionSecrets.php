<?php

namespace App\Adapters\Finance\MercadoPago;

final readonly class MercadoPagoConnectionSecrets
{
    public function __construct(
        public string $webhookSecret,
        public string $accessToken,
        public string $applicationId,
        public string $userId,
        public bool $liveMode
    ) {
    }
}
