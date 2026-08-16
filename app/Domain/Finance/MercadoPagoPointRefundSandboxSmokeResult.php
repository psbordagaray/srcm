<?php

namespace App\Domain\Finance;

use App\Enums\FinancialMovementStatus;

final readonly class MercadoPagoPointRefundSandboxSmokeResult
{
    public function __construct(
        public string $orderId,
        public string $refundId,
        public string $terminalId,
        public int $amountMinor,
        public string $currencyCode,
        public FinancialMovementStatus $status
    ) {
    }
}
