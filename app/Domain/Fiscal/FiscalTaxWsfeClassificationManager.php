<?php

namespace App\Domain\Fiscal;

use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\FiscalTaxWsfeBucket;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentTax;
use App\Models\FiscalDocumentTaxWsfeIdentity;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class FiscalTaxWsfeClassificationManager
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization
    ) {
    }

    public function record(
        FiscalTaxWsfeClassificationData $data,
        User $actor
    ): FiscalDocument {
        $organizationId = $this->organizationId($actor);

        return DB::transaction(function () use (
            $data,
            $actor,
            $organizationId
        ): FiscalDocument {
            $document = FiscalDocument::query()
                ->forOrganization($organizationId)
                ->lockForUpdate()
                ->find($data->fiscalDocumentId);

            if (! $document) {
                throw new DomainException(
                    'El documento fiscal no pertenece a la organización activa.'
                );
            }

            /** @var Collection<int,FiscalDocumentTax> $components */
            $components = FiscalDocumentTax::query()
                ->forOrganization($organizationId)
                ->where('fiscal_document_id', $document->id)
                ->orderBy('position')
                ->lockForUpdate()
                ->get();

            if ($components->isEmpty()) {
                throw new DomainException(
                    'La identidad tributaria WSFE requiere una composición tributaria explícita.'
                );
            }

            $normalized = $this->normalizeCompleteSet(
                $data->identities,
                $components,
                $document,
                $organizationId
            );

            $existing = FiscalDocumentTaxWsfeIdentity::query()
                ->forOrganization($organizationId)
                ->where('fiscal_document_id', $document->id)
                ->orderBy('fiscal_document_tax_id')
                ->lockForUpdate()
                ->get();

            if ($existing->isNotEmpty()) {
                $this->assertExistingSetMatches(
                    $components,
                    $existing,
                    $normalized
                );

                return $document
                    ->refresh()
                    ->load('taxComponents.wsfeIdentity');
            }

            if ($document->authorizationAttempts()->exists()) {
                throw new DomainException(
                    'La identidad tributaria WSFE no puede registrarse después de un intento de autorización.'
                );
            }

            foreach ($normalized as $identity) {
                FiscalDocumentTaxWsfeIdentity::query()->create([
                    'organization_id' => $organizationId,
                    'fiscal_document_id' => $document->id,
                    'fiscal_document_tax_id' => $identity['fiscal_document_tax_id'],
                    'bucket' => $identity['bucket'],
                    'arca_id' => $identity['arca_id'],
                    'tribute_description' => $identity['tribute_description'],
                    'recorded_at' => CarbonImmutable::now(),
                    'recorded_by_user_id' => $actor->id,
                ]);
            }

            $ready = $document
                ->refresh()
                ->load('taxComponents.wsfeIdentity');

            $this->assertCompleteForRequestComposition($ready);

            return $ready;
        }, 3);
    }

    /**
     * @return list<array{
     *   fiscal_document_tax_id:int,
     *   position:int,
     *   bucket:FiscalTaxWsfeBucket,
     *   arca_id:int,
     *   tribute_description:?string,
     *   taxable_base_minor:int,
     *   rate_basis_points:int,
     *   tax_amount_minor:int
     * }>
     */
    public function assertCompleteForRequestComposition(
        FiscalDocument $document
    ): array {
        $components = FiscalDocumentTax::query()
            ->where('organization_id', $document->organization_id)
            ->where('fiscal_document_id', $document->id)
            ->with('wsfeIdentity')
            ->orderBy('position')
            ->get();

        if ($components->isEmpty()) {
            throw new DomainException(
                'El documento no posee composición tributaria explícita para WSFE.'
            );
        }

        $result = [];

        foreach ($components as $component) {
            $identity = $component->wsfeIdentity;

            if (
                ! $identity
                || (int) $identity->organization_id
                    !== (int) $document->organization_id
                || (int) $identity->fiscal_document_id
                    !== (int) $document->id
                || (int) $identity->fiscal_document_tax_id
                    !== (int) $component->id
            ) {
                throw new DomainException(
                    'La clasificación tributaria WSFE está incompleta o cruza fronteras fiscales.'
                );
            }

            $bucket = $identity->bucket;

            if (! $bucket instanceof FiscalTaxWsfeBucket) {
                throw new DomainException(
                    'La clasificación tributaria WSFE contiene un bucket inválido.'
                );
            }

            $this->assertProviderIdentity(
                $bucket,
                (int) $identity->arca_id,
                $identity->tribute_description
            );

            $result[] = [
                'fiscal_document_tax_id' => (int) $component->id,
                'position' => (int) $component->position,
                'bucket' => $bucket,
                'arca_id' => (int) $identity->arca_id,
                'tribute_description' => $identity->tribute_description,
                'taxable_base_minor' => (int) $component->taxable_base_minor,
                'rate_basis_points' => (int) $component->rate_basis_points,
                'tax_amount_minor' => (int) $component->tax_amount_minor,
            ];
        }

        return $result;
    }

    /**
     * @param list<FiscalTaxWsfeIdentityData> $identities
     * @param Collection<int,FiscalDocumentTax> $components
     * @return list<array{
     *   fiscal_document_tax_id:int,
     *   bucket:string,
     *   arca_id:int,
     *   tribute_description:?string
     * }>
     */
    private function normalizeCompleteSet(
        array $identities,
        Collection $components,
        FiscalDocument $document,
        int $organizationId
    ): array {
        if ($identities === []) {
            throw new DomainException(
                'Debe clasificar explícitamente todos los componentes tributarios para WSFE.'
            );
        }

        $componentsById = $components->keyBy(
            fn (FiscalDocumentTax $component): int =>
                (int) $component->id
        );

        if (count($identities) !== $componentsById->count()) {
            throw new DomainException(
                'La clasificación WSFE debe cubrir exactamente todos los componentes tributarios.'
            );
        }

        $seen = [];
        $normalized = [];

        foreach ($identities as $identity) {
            if (! $identity instanceof FiscalTaxWsfeIdentityData) {
                throw new DomainException(
                    'Cada identidad tributaria debe ser FiscalTaxWsfeIdentityData.'
                );
            }

            $taxId = $identity->fiscalDocumentTaxId;

            if (
                $taxId <= 0
                || isset($seen[$taxId])
                || ! $componentsById->has($taxId)
            ) {
                throw new DomainException(
                    'La identidad WSFE contiene un componente faltante, duplicado o ajeno al documento.'
                );
            }

            /** @var FiscalDocumentTax $component */
            $component = $componentsById->get($taxId);

            if (
                (int) $component->organization_id !== $organizationId
                || (int) $component->fiscal_document_id !== (int) $document->id
            ) {
                throw new DomainException(
                    'El componente tributario no pertenece a la identidad fiscal activa.'
                );
            }

            $description = $this->normalizeDescription(
                $identity->tributeDescription
            );

            $this->assertProviderIdentity(
                $identity->bucket,
                $identity->arcaId,
                $description
            );

            $seen[$taxId] = true;

            $normalized[] = [
                'fiscal_document_tax_id' => $taxId,
                'bucket' => $identity->bucket->value,
                'arca_id' => $identity->arcaId,
                'tribute_description' => $description,
            ];
        }

        usort(
            $normalized,
            static fn (array $left, array $right): int =>
                $left['fiscal_document_tax_id']
                <=> $right['fiscal_document_tax_id']
        );

        return $normalized;
    }

    /**
     * @param Collection<int,FiscalDocumentTax> $components
     * @param Collection<int,FiscalDocumentTaxWsfeIdentity> $existing
     * @param list<array{
     *   fiscal_document_tax_id:int,
     *   bucket:string,
     *   arca_id:int,
     *   tribute_description:?string
     * }> $normalized
     */
    private function assertExistingSetMatches(
        Collection $components,
        Collection $existing,
        array $normalized
    ): void {
        if (
            $existing->count() !== $components->count()
            || $existing->count() !== count($normalized)
        ) {
            throw new DomainException(
                'Existe una clasificación tributaria WSFE parcial; el documento queda bloqueado para composición.'
            );
        }

        $actual = $existing
            ->map(fn (FiscalDocumentTaxWsfeIdentity $identity): array => [
                'fiscal_document_tax_id' =>
                    (int) $identity->fiscal_document_tax_id,
                'bucket' => $identity->bucket instanceof FiscalTaxWsfeBucket
                    ? $identity->bucket->value
                    : (string) $identity->getRawOriginal('bucket'),
                'arca_id' => (int) $identity->arca_id,
                'tribute_description' =>
                    $this->normalizeDescription(
                        $identity->tribute_description
                    ),
            ])
            ->sortBy('fiscal_document_tax_id')
            ->values()
            ->all();

        if ($actual !== $normalized) {
            throw new DomainException(
                'La clasificación tributaria WSFE ya existe con otra identidad inmutable.'
            );
        }

        $document = $components->first()?->document;

        if (! $document) {
            throw new DomainException(
                'No se pudo revalidar el documento de la clasificación tributaria WSFE.'
            );
        }

        $this->assertCompleteForRequestComposition($document);
    }

    private function assertProviderIdentity(
        FiscalTaxWsfeBucket $bucket,
        int $arcaId,
        ?string $description
    ): void {
        if ($arcaId < 1 || $arcaId > 99) {
            throw new DomainException(
                'El Id tributario WSFE debe ser un entero positivo de hasta dos dígitos.'
            );
        }

        if (
            $bucket === FiscalTaxWsfeBucket::Iva
            && $description !== null
        ) {
            throw new DomainException(
                'El bucket IVA no admite descripción de tributo.'
            );
        }

        if (
            $bucket === FiscalTaxWsfeBucket::Tributo
            && $description !== null
            && mb_strlen($description) > 80
        ) {
            throw new DomainException(
                'La descripción opcional del tributo WSFE admite hasta 80 caracteres.'
            );
        }
    }

    private function normalizeDescription(
        ?string $description
    ): ?string {
        if ($description === null) {
            return null;
        }

        $normalized = trim($description);

        return $normalized === ''
            ? null
            : $normalized;
    }

    private function organizationId(User $actor): int
    {
        $organizationId = $this->currentOrganization->id($actor);
        $role = $this->currentOrganization->roleFor($actor);

        if (! ($role?->canManageOrganization() ?? false)) {
            throw new DomainException(
                'Sólo un administrador puede registrar identidad tributaria WSFE.'
            );
        }

        return $organizationId;
    }
}
