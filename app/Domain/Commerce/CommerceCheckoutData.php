<?php

namespace App\Domain\Commerce;

use DateTimeInterface;
use InvalidArgumentException;

final readonly class CommerceCheckoutData
{
    /**
     * @param list<CommerceProductLineData> $productLines
     * @param list<CommercePaymentData> $payments
     */
    public function __construct(
        public string $currencyCode,
        public string $idempotencyKey,
        public array $payments,
        public ?int $receivableAmountMinor = null,
        public ?DateTimeInterface $receivableDueOn = null,
        public array $productLines = [],
        public ?int $serviceOrderId = null,
        public ?int $customerBusinessPartyId = null,
        public ?string $customerName = null,
        public ?string $customerDocument = null,
        public ?string $notes = null,
        public ?DateTimeInterface $soldAt = null,
        public ?string $customerCreditOverrideReason = null,
        public ?int $receivableInstallmentCount = null,
        public ?CommerceSettlementComponentEvidence $receivableSettlementComponentEvidence = null,
    ) {
        foreach ($this->payments as $index => $payment) {
            if (
                ! $payment instanceof CommercePaymentData
                || $payment->settlementComponentEvidence === null
            ) {
                continue;
            }

            $expectedComponentId = 'payments.'.$index.'.amount';

            if (
                $payment->settlementComponentEvidence->componentId
                    !== $expectedComponentId
            ) {
                throw new InvalidArgumentException(
                    'Commerce payment settlement component transport id does not match payment position.'
                );
            }
        }

        $receivableEvidence =
            $this->receivableSettlementComponentEvidence;

        if ($receivableEvidence === null) {
            return;
        }

        if (
            $this->receivableAmountMinor === null
            || $receivableEvidence->componentType
                !== CommerceSettlementComponentEvidence::TYPE_RECEIVABLE_AMOUNT
            || $receivableEvidence->componentId
                !== CommerceSettlementComponentEvidence::RECEIVABLE_COMPONENT_ID
            || $receivableEvidence->minorValue
                !== $this->receivableAmountMinor
            || $receivableEvidence->hasConditionalResidualCandidate()
        ) {
            throw new InvalidArgumentException(
                'Commerce receivable settlement component transport evidence is inconsistent.'
            );
        }
    }
}