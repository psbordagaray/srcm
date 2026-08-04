<?php

namespace App\Enums;

enum ServiceWarrantyClaimStatus: string
{
    case PendingReview = 'pending_review';
    case Accepted = 'accepted';
    case PartiallyAccepted = 'partially_accepted';
    case Rejected = 'rejected';
    case InCorrectiveWork = 'in_corrective_work';
    case ReadyForReturn = 'ready_for_return';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::PendingReview => 'Pendiente de evaluación',
            self::Accepted => 'Garantía aceptada',
            self::PartiallyAccepted => 'Garantía aceptada parcialmente',
            self::Rejected => 'Garantía rechazada',
            self::InCorrectiveWork => 'En trabajo correctivo',
            self::ReadyForReturn => 'Lista para devolver',
            self::Closed => 'Cerrada',
        };
    }

    public function isOpen(): bool
    {
        return $this !== self::Closed;
    }
}
