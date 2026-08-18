<?php

namespace App\Enums;

enum PurchasePaymentExternalResolutionOutcome: string
{
    case TreasuryExceptionAccepted =
        'treasury_exception_accepted';

    case ProviderFollowUpRequired =
        'provider_follow_up_required';

    case SupplierFollowUpRequired =
        'supplier_follow_up_required';

    case EvidenceCorrectionRequired =
        'evidence_correction_required';

    public function label(): string
    {
        return match ($this) {
            self::TreasuryExceptionAccepted =>
                'Excepción de tesorería aceptada',
            self::ProviderFollowUpRequired =>
                'Seguimiento con entidad/proveedor financiero',
            self::SupplierFollowUpRequired =>
                'Seguimiento con proveedor/beneficiario',
            self::EvidenceCorrectionRequired =>
                'Corrección de evidencia requerida',
        };
    }

    public function closesReview(): bool
    {
        return $this === self::TreasuryExceptionAccepted;
    }
}
