<?php

namespace App\Domain\Commerce;

use App\Enums\CommercePaymentMethod;
use DateTimeInterface;

final readonly class CommercePaymentData
{
    public function __construct(
        public CommercePaymentMethod $method,
        public int $amountMinor,
        public ?string $reference = null,
        public ?string $notes = null,
        public ?DateTimeInterface $paidAt = null
    ) {
    }
}
