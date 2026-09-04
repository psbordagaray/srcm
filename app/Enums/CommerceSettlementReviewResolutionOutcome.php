<?php

namespace App\Enums;

enum CommerceSettlementReviewResolutionOutcome: string
{
    case RetryWithReferenceSettlement =
        'retry_with_reference_settlement';

    case AbandonCheckout = 'abandon_checkout';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $outcome): string => $outcome->value,
            self::cases()
        );
    }
}
