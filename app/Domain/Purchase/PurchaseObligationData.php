<?php

namespace App\Domain\Purchase;

use App\Enums\PurchaseObligationCondition;
use App\Enums\PurchaseObligationKind;

final readonly class PurchaseObligationData
{
    public function __construct(
        public int $purchaseReceiptId,
        public PurchaseObligationKind $kind,
        public ?int $beneficiaryBusinessPartyId,
        public PurchaseObligationCondition $paymentCondition,
        public ?string $dueOn = null,
        public ?string $conditionNote = null
    ) {
    }
}
