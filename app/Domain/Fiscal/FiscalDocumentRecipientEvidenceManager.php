<?php

namespace App\Domain\Fiscal;

use App\Domain\Tenancy\CurrentOrganization;
use App\Models\FiscalBusinessPartyProfile;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentRecipientEvidence;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final class FiscalDocumentRecipientEvidenceManager
{
    public function __construct(private readonly CurrentOrganization $currentOrganization)
    {
    }

    public function record(
        FiscalDocumentRecipientEvidenceData $data,
        User $actor
    ): FiscalDocumentRecipientEvidence {
        $organizationId = $this->organization($actor);
        $values = $this->normalize($data);

        return DB::transaction(function () use (
            $data,
            $actor,
            $organizationId,
            $values
        ): FiscalDocumentRecipientEvidence {
            $document = FiscalDocument::query()
                ->forOrganization($organizationId)
                ->with('sale')
                ->lockForUpdate()
                ->find($data->fiscalDocumentId);

            if (! $document) {
                throw new DomainException(
                    'El documento no pertenece a la organización activa.'
                );
            }

            $existing = FiscalDocumentRecipientEvidence::query()
                ->forOrganization($organizationId)
                ->where('fiscal_document_id', $document->id)
                ->first();

            if ($existing) {
                if (
                    $existing->document_type_code !== $values['document_type_code']
                    || $existing->document_number !== $values['document_number']
                    || $existing->vat_condition_code !== $values['vat_condition_code']
                ) {
                    throw new DomainException(
                        'El documento ya posee otra evidencia fiscal de receptor.'
                    );
                }

                return $existing;
            }

            if ($document->authorizationAttempts()->exists()) {
                throw new DomainException(
                    'La evidencia fiscal del receptor debe quedar cerrada antes del primer intento de autorización.'
                );
            }

            $this->assertProfileConsistency(
                $document,
                $organizationId,
                $values['document_number'],
                $values['vat_condition_code']
            );

            return FiscalDocumentRecipientEvidence::query()->create([
                'organization_id' => $organizationId,
                'fiscal_document_id' => $document->id,
                ...$values,
                'recorded_at' => CarbonImmutable::now(),
                'recorded_by_user_id' => $actor->id,
            ]);
        }, 3);
    }

    /** @return array{document_type_code:string,document_number:string,vat_condition_code:string} */
    private function normalize(FiscalDocumentRecipientEvidenceData $data): array
    {
        $documentTypeCode = trim($data->documentTypeCode);
        $documentNumber = preg_replace('/\D+/', '', trim($data->documentNumber)) ?? '';
        $vatConditionCode = trim($data->vatConditionCode);

        if (preg_match('/^\d{1,3}$/D', $documentTypeCode) !== 1) {
            throw new DomainException(
                'El tipo de documento fiscal del receptor debe informarse mediante un código numérico explícito.'
            );
        }

        if (preg_match('/^\d{1,20}$/D', $documentNumber) !== 1) {
            throw new DomainException(
                'El número de documento fiscal del receptor debe informarse explícitamente.'
            );
        }

        if (preg_match('/^\d{1,10}$/D', $vatConditionCode) !== 1) {
            throw new DomainException(
                'La condición IVA fiscal del receptor debe informarse mediante un código explícito.'
            );
        }

        return [
            'document_type_code' => $documentTypeCode,
            'document_number' => $documentNumber,
            'vat_condition_code' => $vatConditionCode,
        ];
    }

    private function assertProfileConsistency(
        FiscalDocument $document,
        int $organizationId,
        string $documentNumber,
        string $vatConditionCode
    ): void {
        $businessPartyId = $document->sale?->customer_business_party_id;

        if (! $businessPartyId) {
            return;
        }

        $profile = FiscalBusinessPartyProfile::query()
            ->forOrganization($organizationId)
            ->where('business_party_id', $businessPartyId)
            ->first();

        if (! $profile) {
            return;
        }

        if (filled($profile->tax_id)) {
            $profileTaxId = preg_replace('/\D+/', '', (string) $profile->tax_id) ?? '';

            if ($profileTaxId !== '' && $profileTaxId !== $documentNumber) {
                throw new DomainException(
                    'La identificación fiscal explícita no coincide con el perfil fiscal vigente del receptor.'
                );
            }
        }

        if (trim((string) $profile->vat_condition_code) !== $vatConditionCode) {
            throw new DomainException(
                'La condición IVA explícita no coincide con el perfil fiscal vigente del receptor.'
            );
        }
    }

    private function organization(User $actor): int
    {
        $organizationId = $this->currentOrganization->id($actor);

        if (! ($this->currentOrganization->roleFor($actor)?->canManageOrganization() ?? false)) {
            throw new DomainException(
                'Sólo un administrador puede registrar evidencia fiscal del receptor.'
            );
        }

        return $organizationId;
    }
}
