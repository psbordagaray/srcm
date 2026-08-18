<?php

namespace App\Domain\Fiscal;

use App\Domain\Tenancy\CurrentOrganization;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentCurrencyEvidence;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final class FiscalDocumentCurrencyEvidenceManager
{
    private const QUOTATION_SCALE = 1_000_000;
    private const MAX_QUOTATION_MICROS = 9_999_999_999;

    public function __construct(private readonly CurrentOrganization $currentOrganization)
    {
    }

    public function record(
        FiscalDocumentCurrencyEvidenceData $data,
        User $actor
    ): FiscalDocumentCurrencyEvidence {
        $organizationId = $this->organization($actor);

        return DB::transaction(function () use (
            $data,
            $actor,
            $organizationId
        ): FiscalDocumentCurrencyEvidence {
            $document = FiscalDocument::query()
                ->forOrganization($organizationId)
                ->lockForUpdate()
                ->find($data->fiscalDocumentId);

            if (! $document) {
                throw new DomainException(
                    'El documento no pertenece a la organización activa.'
                );
            }

            $values = $this->values($data, $document);

            $existing = FiscalDocumentCurrencyEvidence::query()
                ->forOrganization($organizationId)
                ->where('fiscal_document_id', $document->id)
                ->first();

            if ($existing) {
                foreach ($values as $field => $value) {
                    if ($field === 'same_currency_settlement') {
                        if ((bool) $existing->{$field} !== $value) {
                            throw new DomainException(
                                'El documento ya posee otra evidencia fiscal de moneda.'
                            );
                        }

                        continue;
                    }

                    if ((string) $existing->{$field} !== (string) $value) {
                        throw new DomainException(
                            'El documento ya posee otra evidencia fiscal de moneda.'
                        );
                    }
                }

                return $existing;
            }

            if ($document->authorizationAttempts()->exists()) {
                throw new DomainException(
                    'La evidencia fiscal de moneda debe quedar cerrada antes del primer intento de autorización.'
                );
            }

            return FiscalDocumentCurrencyEvidence::query()->create([
                'organization_id' => $organizationId,
                'fiscal_document_id' => $document->id,
                ...$values,
                'recorded_at' => CarbonImmutable::now(),
                'recorded_by_user_id' => $actor->id,
            ]);
        }, 3);
    }

    /**
     * @return array{
     *   source_currency_code:string,
     *   arca_currency_code:string,
     *   quotation_micros:int,
     *   same_currency_settlement:bool
     * }
     */
    private function values(
        FiscalDocumentCurrencyEvidenceData $data,
        FiscalDocument $document
    ): array {
        $sourceCurrencyCode = strtoupper(trim($data->sourceCurrencyCode));
        $arcaCurrencyCode = strtoupper(trim($data->arcaCurrencyCode));

        if (! preg_match('/^[A-Z]{3}$/', $sourceCurrencyCode)) {
            throw new DomainException(
                'El código de moneda fuente debe tener tres letras mayúsculas.'
            );
        }

        if (! preg_match('/^[A-Z0-9]{3}$/', $arcaCurrencyCode)) {
            throw new DomainException(
                'MonId debe declararse explícitamente con tres caracteres válidos.'
            );
        }

        $documentCurrencyCode = strtoupper(trim((string) $document->currency_code));

        if ($sourceCurrencyCode !== $documentCurrencyCode) {
            throw new DomainException(
                'La moneda fuente declarada no coincide con la moneda inmutable del documento fiscal.'
            );
        }

        if (
            $data->quotationMicros <= 0
            || $data->quotationMicros > self::MAX_QUOTATION_MICROS
        ) {
            throw new DomainException(
                'MonCotiz debe ser mayor a cero y respetar precisión máxima 4+6.'
            );
        }

        if (
            $arcaCurrencyCode === 'PES'
            && $data->quotationMicros !== self::QUOTATION_SCALE
        ) {
            throw new DomainException(
                'Para MonId PES, MonCotiz debe ser exactamente 1.'
            );
        }

        if (
            $arcaCurrencyCode === 'PES'
            && $data->sameCurrencySettlement
        ) {
            throw new DomainException(
                'CanMisMonExt no puede ser S para MonId PES.'
            );
        }

        return [
            'source_currency_code' => $sourceCurrencyCode,
            'arca_currency_code' => $arcaCurrencyCode,
            'quotation_micros' => $data->quotationMicros,
            'same_currency_settlement' => $data->sameCurrencySettlement,
        ];
    }

    private function organization(User $actor): int
    {
        $organizationId = $this->currentOrganization->id($actor);

        if (! ($this->currentOrganization->roleFor($actor)?->canManageOrganization() ?? false)) {
            throw new DomainException(
                'Sólo un administrador puede registrar evidencia fiscal de moneda.'
            );
        }

        return $organizationId;
    }
}
