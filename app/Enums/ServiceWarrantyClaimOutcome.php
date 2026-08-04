<?php

namespace App\Enums;

enum ServiceWarrantyClaimOutcome: string
{
    case Accepted = 'accepted';
    case PartiallyAccepted = 'partially_accepted';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Accepted => 'Aceptada',
            self::PartiallyAccepted => 'Aceptada parcialmente',
            self::Rejected => 'Rechazada',
        };
    }

    public function authorizesCorrectiveWork(): bool
    {
        return $this !== self::Rejected;
    }
}
