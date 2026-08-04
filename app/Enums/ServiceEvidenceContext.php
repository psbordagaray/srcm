<?php

namespace App\Enums;

enum ServiceEvidenceContext: string
{
    case Order = 'order';
    case Intake = 'intake';
    case Diagnostic = 'diagnostic';
    case WorkItem = 'work_item';
    case PartRequirement = 'part_requirement';
    case CustodyEvent = 'custody_event';
    case QualityInspection = 'quality_inspection';
    case Delivery = 'delivery';
    case CancellationRequest = 'cancellation_request';
    case CancellationResolution = 'cancellation_resolution';
    case CancellationReturn = 'cancellation_return';
    case WarrantyClaim = 'warranty_claim';
    case WarrantyResolution = 'warranty_resolution';
    case WarrantyReturn = 'warranty_return';

    public function label(): string
    {
        return match ($this) {
            self::Order => 'Expediente general',
            self::Intake => 'Ingreso y estado físico',
            self::Diagnostic => 'Diagnóstico',
            self::WorkItem => 'Trabajo',
            self::PartRequirement => 'Repuesto',
            self::CustodyEvent => 'Custodia',
            self::QualityInspection => 'Control de calidad',
            self::Delivery => 'Entrega',
            self::CancellationRequest => 'Solicitud de cancelación',
            self::CancellationResolution => 'Resolución de cancelación',
            self::CancellationReturn => 'Devolución por cancelación',
            self::WarrantyClaim => 'Reclamo de garantía',
            self::WarrantyResolution => 'Resolución de garantía',
            self::WarrantyReturn => 'Devolución de garantía',
        };
    }

    public function referenceColumn(): ?string
    {
        return match ($this) {
            self::Order => null,
            self::Intake => 'service_order_intake_id',
            self::Diagnostic => 'service_diagnostic_id',
            self::WorkItem => 'service_work_item_id',
            self::PartRequirement => 'service_part_requirement_id',
            self::CustodyEvent => 'service_custody_event_id',
            self::QualityInspection => 'service_quality_inspection_id',
            self::Delivery => 'service_delivery_id',
            self::CancellationRequest => 'service_cancellation_request_id',
            self::CancellationResolution => 'service_cancellation_resolution_id',
            self::CancellationReturn => 'service_cancellation_return_id',
            self::WarrantyClaim => 'service_warranty_claim_id',
            self::WarrantyResolution => 'service_warranty_claim_resolution_id',
            self::WarrantyReturn => 'service_warranty_claim_return_id',
        };
    }

    public function requiresReference(): bool
    {
        return $this->referenceColumn() !== null;
    }
}
