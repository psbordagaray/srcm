<?php

namespace App\Domain\Fiscal;

use App\Domain\Tenancy\CurrentOrganization;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentIssueDate;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final class FiscalDocumentIssueDateManager
{
    public function __construct(private readonly CurrentOrganization $currentOrganization)
    {
    }

    public function record(
        FiscalDocumentIssueDateData $data,
        User $actor
    ): FiscalDocumentIssueDate {
        $organizationId = $this->organization($actor);
        $issueDate = $data->issueDate->toDateString();

        return DB::transaction(function () use (
            $data,
            $actor,
            $organizationId,
            $issueDate
        ): FiscalDocumentIssueDate {
            $document = FiscalDocument::query()
                ->forOrganization($organizationId)
                ->lockForUpdate()
                ->find($data->fiscalDocumentId);

            if (! $document) {
                throw new DomainException(
                    'El documento no pertenece a la organización activa.'
                );
            }

            $existing = FiscalDocumentIssueDate::query()
                ->forOrganization($organizationId)
                ->where('fiscal_document_id', $document->id)
                ->first();

            if ($existing) {
                if ($existing->issue_date->toDateString() !== $issueDate) {
                    throw new DomainException(
                        'El documento ya posee otra fecha fiscal de comprobante.'
                    );
                }

                return $existing;
            }

            if ($document->authorizationAttempts()->exists()) {
                throw new DomainException(
                    'La fecha fiscal del comprobante debe quedar cerrada antes del primer intento de autorización.'
                );
            }

            return FiscalDocumentIssueDate::query()->create([
                'organization_id' => $organizationId,
                'fiscal_document_id' => $document->id,
                'issue_date' => $issueDate,
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
                'Sólo un administrador puede registrar la fecha fiscal del comprobante.'
            );
        }

        return $organizationId;
    }
}
