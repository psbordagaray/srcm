<?php

namespace App\Domain\Commerce;

use App\Domain\Tenancy\CurrentOrganization;
use App\Models\BusinessParty;
use DomainException;
use Illuminate\Support\Facades\DB;

final class BusinessPartyIdentityManager
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization
    ) {
    }

    /**
     * Creates a role-less commercial identity.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): BusinessParty
    {
        $organizationId = $this->currentOrganization->id();

        return DB::transaction(function () use (
            $organizationId,
            $data
        ): BusinessParty {
            $this->assertNewIdentityIsSafe(
                $organizationId,
                $data
            );

            return BusinessParty::query()->create([
                'organization_id' => $organizationId,
                ...$this->partyData($data),
            ]);
        });
    }

    /**
     * Resolves an existing identity by strong evidence or creates it.
     *
     * Used by role managers such as CustomerManager and SupplierManager.
     *
     * @param  array<string, mixed>  $data
     */
    public function resolveOrCreate(array $data): BusinessParty
    {
        $organizationId = $this->currentOrganization->id();

        return DB::transaction(function () use (
            $organizationId,
            $data
        ): BusinessParty {
            $party = $this->findAdoptableParty(
                $organizationId,
                $data
            );

            if ($party) {
                $this->enrichMissingPartyData($party, $data);

                return $party->fresh();
            }

            $this->assertNewIdentityIsSafe(
                $organizationId,
                $data
            );

            return BusinessParty::query()->create([
                'organization_id' => $organizationId,
                ...$this->partyData($data),
            ]);
        });
    }

    /**
     * Updates shared identity data without changing any commercial role.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(
        BusinessParty $party,
        array $data
    ): BusinessParty {
        $organizationId = $this->currentOrganization->id();

        $this->assertBelongsToOrganization(
            $party,
            $organizationId
        );

        return DB::transaction(function () use (
            $party,
            $data,
            $organizationId
        ): BusinessParty {
            $locked = BusinessParty::query()
                ->forOrganization($organizationId)
                ->whereKey($party->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertUpdatedIdentityIsSafe(
                $organizationId,
                $locked,
                $data
            );

            $locked->update($this->partyData($data));

            return $locked->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function findAdoptableParty(
        int $organizationId,
        array $data
    ): ?BusinessParty {
        $normalizedTaxId = BusinessParty::normalizeTaxId(
            (string) ($data['tax_id'] ?? '')
        );
        $email = mb_strtolower(trim(
            (string) ($data['email'] ?? '')
        ));

        $taxMatch = $normalizedTaxId !== ''
            ? BusinessParty::query()
                ->forOrganization($organizationId)
                ->where(
                    'normalized_tax_id',
                    $normalizedTaxId
                )
                ->lockForUpdate()
                ->first()
            : null;

        $emailMatches = $email !== ''
            ? BusinessParty::query()
                ->forOrganization($organizationId)
                ->where('email', $email)
                ->lockForUpdate()
                ->get()
            : collect();

        if ($emailMatches->count() > 1) {
            throw new DomainException(
                'El correo coincide con más de una identidad comercial. Requiere revisión manual.'
            );
        }

        $emailMatch = $emailMatches->first();

        if (
            $taxMatch
            && $emailMatch
            && $taxMatch->getKey() !== $emailMatch->getKey()
        ) {
            throw new DomainException(
                'El documento fiscal y el correo apuntan a identidades comerciales diferentes dentro de la organización activa.'
            );
        }

        $candidate = $taxMatch ?? $emailMatch;

        if (! $candidate) {
            return null;
        }

        if ($candidate->party_type !== $data['party_type']) {
            throw new DomainException(
                'La identidad encontrada posee otro tipo de parte comercial.'
            );
        }

        if (
            $candidate->normalized_name
            !== BusinessParty::normalizeName($data['name'])
        ) {
            throw new DomainException(
                'Existe una identidad con el mismo documento o correo, pero con otro nombre. Requiere revisión manual.'
            );
        }

        return $candidate;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertNewIdentityIsSafe(
        int $organizationId,
        array $data
    ): void {
        $normalizedTaxId = BusinessParty::normalizeTaxId(
            (string) ($data['tax_id'] ?? '')
        );
        $email = mb_strtolower(trim(
            (string) ($data['email'] ?? '')
        ));

        if (
            $normalizedTaxId !== ''
            && BusinessParty::query()
                ->forOrganization($organizationId)
                ->where(
                    'normalized_tax_id',
                    $normalizedTaxId
                )
                ->lockForUpdate()
                ->exists()
        ) {
            throw new DomainException(
                'El documento fiscal ya pertenece a otra identidad comercial de esta organización.'
            );
        }

        if (
            $email !== ''
            && BusinessParty::query()
                ->forOrganization($organizationId)
                ->where('email', $email)
                ->lockForUpdate()
                ->exists()
        ) {
            throw new DomainException(
                'El correo ya pertenece a otra identidad comercial de esta organización.'
            );
        }

        if (
            BusinessParty::query()
                ->forOrganization($organizationId)
                ->where('party_type', $data['party_type'])
                ->where(
                    'normalized_name',
                    BusinessParty::normalizeName($data['name'])
                )
                ->lockForUpdate()
                ->exists()
        ) {
            throw new DomainException(
                'Ya existe una parte comercial con un nombre equivalente en la organización activa. Agregá documento fiscal o correo para revisar la identidad sin duplicar.'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertUpdatedIdentityIsSafe(
        int $organizationId,
        BusinessParty $party,
        array $data
    ): void {
        $normalizedTaxId = BusinessParty::normalizeTaxId(
            (string) ($data['tax_id'] ?? '')
        );
        $email = mb_strtolower(trim(
            (string) ($data['email'] ?? '')
        ));

        if (
            $normalizedTaxId !== ''
            && BusinessParty::query()
                ->forOrganization($organizationId)
                ->whereKeyNot($party->getKey())
                ->where(
                    'normalized_tax_id',
                    $normalizedTaxId
                )
                ->exists()
        ) {
            throw new DomainException(
                'El documento fiscal ya pertenece a otra identidad comercial de esta organización.'
            );
        }

        if (
            $email !== ''
            && BusinessParty::query()
                ->forOrganization($organizationId)
                ->whereKeyNot($party->getKey())
                ->where('email', $email)
                ->exists()
        ) {
            throw new DomainException(
                'El correo ya pertenece a otra identidad comercial de esta organización.'
            );
        }

        if (
            BusinessParty::query()
                ->forOrganization($organizationId)
                ->whereKeyNot($party->getKey())
                ->where('party_type', $data['party_type'])
                ->where(
                    'normalized_name',
                    BusinessParty::normalizeName($data['name'])
                )
                ->exists()
        ) {
            throw new DomainException(
                'Ya existe otra parte comercial con un nombre equivalente en esta organización.'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function enrichMissingPartyData(
        BusinessParty $party,
        array $data
    ): void {
        $updates = [];

        foreach (
            ['tax_id', 'email', 'phone', 'website']
            as $field
        ) {
            if (
                blank($party->{$field})
                && filled($data[$field] ?? null)
            ) {
                $updates[$field] = $data[$field];
            }
        }

        if ($updates !== []) {
            $party->update($updates);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function partyData(array $data): array
    {
        return [
            'party_type' => $data['party_type'],
            'name' => $data['name'],
            'tax_id' => $data['tax_id'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'website' => $data['website'] ?? null,
        ];
    }

    private function assertBelongsToOrganization(
        BusinessParty $party,
        int $organizationId
    ): void {
        if ((int) $party->organization_id !== $organizationId) {
            throw new DomainException(
                'La identidad comercial no pertenece a la organización activa.'
            );
        }
    }
}
