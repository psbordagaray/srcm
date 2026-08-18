<?php

namespace App\Domain\Fiscal;

use App\Domain\Tenancy\CurrentOrganization;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentConcept;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final class FiscalDocumentConceptManager
{
    public function __construct(private readonly CurrentOrganization $currentOrganization)
    {
    }

    public function record(FiscalDocumentConceptData $data, User $actor): FiscalDocumentConcept
    {
        $organizationId = $this->organization($actor);
        $this->assertPeriod($data);

        return DB::transaction(function () use ($data, $actor, $organizationId): FiscalDocumentConcept {
            $document = FiscalDocument::query()
                ->forOrganization($organizationId)
                ->lockForUpdate()
                ->find($data->fiscalDocumentId);

            if (! $document) {
                throw new DomainException('El documento no pertenece a la organización activa.');
            }

            $existing = FiscalDocumentConcept::query()
                ->where('fiscal_document_id', $document->id)
                ->first();

            if ($existing) {
                if ($existing->concept !== $data->concept
                    || $existing->service_period_from?->toDateString() !== $data->servicePeriodFrom?->toDateString()
                    || $existing->service_period_to?->toDateString() !== $data->servicePeriodTo?->toDateString()) {
                    throw new DomainException('El documento ya posee otro concepto fiscal.');
                }

                return $existing;
            }

            return FiscalDocumentConcept::query()->create([
                'organization_id' => $organizationId,
                'fiscal_document_id' => $document->id,
                'concept' => $data->concept,
                'service_period_from' => $data->servicePeriodFrom,
                'service_period_to' => $data->servicePeriodTo,
                'recorded_at' => CarbonImmutable::now(),
                'recorded_by_user_id' => $actor->id,
            ]);
        }, 3);
    }

    private function assertPeriod(FiscalDocumentConceptData $data): void
    {
        $requiresPeriod = $data->concept->requiresServicePeriod();
        if ($requiresPeriod && (! $data->servicePeriodFrom || ! $data->servicePeriodTo)) {
            throw new DomainException('Los servicios requieren período desde y hasta explícito.');
        }
        if (! $requiresPeriod && ($data->servicePeriodFrom || $data->servicePeriodTo)) {
            throw new DomainException('Los productos no admiten período de servicios.');
        }
        if ($data->servicePeriodFrom && $data->servicePeriodTo && $data->servicePeriodFrom->gt($data->servicePeriodTo)) {
            throw new DomainException('El período de servicios es inválido.');
        }
    }

    private function organization(User $actor): int
    {
        $organizationId = $this->currentOrganization->id($actor);
        if (! ($this->currentOrganization->roleFor($actor)?->canManageOrganization() ?? false)) {
            throw new DomainException('Sólo un administrador puede registrar concepto fiscal.');
        }

        return $organizationId;
    }
}
