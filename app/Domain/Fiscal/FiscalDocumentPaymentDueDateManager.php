<?php

namespace App\Domain\Fiscal;

use App\Domain\Tenancy\CurrentOrganization;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentPaymentDueDate;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final class FiscalDocumentPaymentDueDateManager
{
    public function __construct(private readonly CurrentOrganization $currentOrganization)
    {
    }

    public function record(
        FiscalDocumentPaymentDueDateData $data,
        User $actor
    ): FiscalDocumentPaymentDueDate {
        $organizationId = $this->organization($actor);
        $paymentDueDate = $data->paymentDueDate->toDateString();

        return DB::transaction(function () use (
            $data,
            $actor,
            $organizationId,
            $paymentDueDate
        ): FiscalDocumentPaymentDueDate {
            $document = FiscalDocument::query()
                ->forOrganization($organizationId)
                ->lockForUpdate()
                ->find($data->fiscalDocumentId);

            if (! $document) {
                throw new DomainException(
                    'El documento no pertenece a la organización activa.'
                );
            }

            $existing = FiscalDocumentPaymentDueDate::query()
                ->forOrganization($organizationId)
                ->where('fiscal_document_id', $document->id)
                ->first();

            if ($existing) {
                if ($existing->payment_due_date->toDateString() !== $paymentDueDate) {
                    throw new DomainException(
                        'El documento ya posee otra fecha fiscal de vencimiento de pago.'
                    );
                }

                return $existing;
            }

            if ($document->authorizationAttempts()->exists()) {
                throw new DomainException(
                    'FchVtoPago debe quedar cerrada antes del primer intento de autorización.'
                );
            }

            $concept = $document->conceptRecord()->first();

            if (! $concept) {
                throw new DomainException(
                    'Debe existir concepto fiscal explícito antes de registrar FchVtoPago.'
                );
            }

            if (! $concept->concept->requiresServicePeriod()) {
                throw new DomainException(
                    'Este subcorte no admite FchVtoPago para comprobantes sólo de productos.'
                );
            }

            if (! $concept->service_period_from || ! $concept->service_period_to) {
                throw new DomainException(
                    'Los conceptos con servicios requieren período desde y hasta antes de FchVtoPago.'
                );
            }

            $issueDate = $document->issueDateRecord()->first();

            if (! $issueDate) {
                throw new DomainException(
                    'Debe existir CbteFch explícita antes de registrar FchVtoPago.'
                );
            }

            if ($data->paymentDueDate->lt($issueDate->issue_date)) {
                throw new DomainException(
                    'FchVtoPago debe ser igual o posterior a CbteFch.'
                );
            }

            return FiscalDocumentPaymentDueDate::query()->create([
                'organization_id' => $organizationId,
                'fiscal_document_id' => $document->id,
                'payment_due_date' => $paymentDueDate,
                'recorded_at' => CarbonImmutable::now(),
                'recorded_by_user_id' => $actor->id,
            ]);
        }, 3);
    }

    private function organization(User $actor): int
    {
        $organizationId = $this->currentOrganization->id($actor);

        if (! ($this->currentOrganization->roleFor($actor)?->canManageOrganization() ?? false)) {
            throw new DomainException(
                'Sólo un administrador puede registrar FchVtoPago.'
            );
        }

        return $organizationId;
    }
}
