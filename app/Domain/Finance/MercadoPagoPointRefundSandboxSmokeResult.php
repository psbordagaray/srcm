<?php

namespace App\Domain\Finance;

final readonly class MercadoPagoPointRefundSandboxSmokeResult
{
    public function __construct(
        public string $orderId,
        public string $paymentId,
        public string $terminalId,
        public int $amountMinor,
        public string $currencyCode,
        public string $orderStatus
    ) {
    }
}
