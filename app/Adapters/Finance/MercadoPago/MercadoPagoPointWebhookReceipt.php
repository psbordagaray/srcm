<?php

namespace App\Adapters\Finance\MercadoPago;

final readonly class MercadoPagoPointWebhookReceipt
{
    public function __construct(
        public string $connectionPublicId,
        public string $resourceId,
        public string $notificationId
    ) {
    }
}
