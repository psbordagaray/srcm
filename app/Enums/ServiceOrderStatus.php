<?php

namespace App\Enums;

enum ServiceOrderStatus: string
{
    case Received = 'received';
    case Diagnosing = 'diagnosing';
    case AwaitingApproval = 'awaiting_approval';
    case AwaitingParts = 'awaiting_parts';
    case InProgress = 'in_progress';
    case WithExternalProvider = 'with_external_provider';
    case QualityControl = 'quality_control';
    case ReadyForDelivery = 'ready_for_delivery';
    case Delivered = 'delivered';
    case CancellationPending = 'cancellation_pending';
    case ReadyForReturn = 'ready_for_return';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Recibida',
            self::Diagnosing => 'En diagnóstico',
            self::AwaitingApproval => 'Esperando aprobación',
            self::AwaitingParts => 'Esperando repuestos',
            self::InProgress => 'En reparación',
            self::WithExternalProvider => 'Con prestador externo',
            self::QualityControl => 'En control de calidad',
            self::ReadyForDelivery => 'Lista para entregar',
            self::Delivered => 'Entregada',
            self::CancellationPending => 'Cancelación solicitada',
            self::ReadyForReturn => 'Lista para devolver',
            self::Cancelled => 'Cancelada y devuelta',
        };
    }
}
