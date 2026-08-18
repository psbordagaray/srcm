<?php

namespace App\Domain\Fiscal;

use App\Domain\Tenancy\CurrentOrganization;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentMonetarySummary;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final class FiscalDocumentMonetarySummaryManager
{
    public function __construct(private readonly CurrentOrganization $currentOrganization)
    {
    }

    public function record(
        FiscalDocumentMonetarySummaryData $data,
        User $actor
    ): FiscalDocumentMonetarySummary {
        $organizationId = $this->organization($actor);
        $values = $this->values($data);

        return DB::transaction(function () use (
            $data,
            $actor,
            $organizationId,
            $values
        ): FiscalDocumentMonetarySummary {
            $document = FiscalDocument::query()
                ->forOrganization($organizationId)
                ->lockForUpdate()
                ->find($data->fiscalDocumentId);

            if (! $document) {
                throw new DomainException(
                    'El documento no pertenece a la organización activa.'
                );
            }

            $existing = FiscalDocumentMonetarySummary::query()
                ->forOrganization($organizationId)
                ->where('fiscal_document_id', $document->id)
                ->first();

            if ($existing) {
                foreach ($values as $field => $value) {
                    if ((int) $existing->{$field} !== $value) {
                        throw new DomainException(
                            'El documento ya posee otro resumen monetario fiscal.'
                        );
                    }
                }

                return $existing;
            }

            if ($document->authorizationAttempts()->exists()) {
                throw new DomainException(
                    'El resumen monetario fiscal debe quedar cerrado antes del primer intento de autorización.'
                );
            }

            if (! $document->taxComponents()->exists()) {
                throw new DomainException(
                    'El resumen monetario fiscal requiere composición tributaria explícita previa.'
                );
            }

            $calculatedTotal = array_sum($values);

            if ($calculatedTotal !== (int) $document->total_minor) {
                throw new DomainException(
                    'El resumen monetario fiscal no coincide con el total inmutable del documento.'
                );
            }

            $taxAmountMinor = (int) $document->taxComponents()
                ->sum('tax_amount_minor');

            if (
                $taxAmountMinor
                !== $values['tributes_amount_minor'] + $values['vat_amount_minor']
            ) {
                throw new DomainException(
                    'IVA y tributos del resumen no coinciden con la composición tributaria explícita.'
                );
            }

            return FiscalDocumentMonetarySummary::query()->create([
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
     *   non_taxed_amount_minor:int,
     *   net_taxable_amount_minor:int,
     *   exempt_amount_minor:int,
     *   tributes_amount_minor:int,
     *   vat_amount_minor:int
     * }
     */
    private function values(FiscalDocumentMonetarySummaryData $data): array
    {
        $values = [
            'non_taxed_amount_minor' => $data->nonTaxedAmountMinor,
            'net_taxable_amount_minor' => $data->netTaxableAmountMinor,
            'exempt_amount_minor' => $data->exemptAmountMinor,
            'tributes_amount_minor' => $data->tributesAmountMinor,
            'vat_amount_minor' => $data->vatAmountMinor,
        ];

        foreach ($values as $value) {
            if ($value < 0) {
                throw new DomainException(
                    'Los importes del resumen monetario fiscal no pueden ser negativos.'
                );
            }
        }

        return $values;
    }

    private function organization(User $actor): int
    {
        $organizationId = $this->currentOrganization->id($actor);

        if (! ($this->currentOrganization->roleFor($actor)?->canManageOrganization() ?? false)) {
            throw new DomainException(
                'Sólo un administrador puede registrar el resumen monetario fiscal.'
            );
        }

        return $organizationId;
    }
}
