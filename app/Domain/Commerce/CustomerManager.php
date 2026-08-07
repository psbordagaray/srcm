<?php

namespace App\Domain\Commerce;

use App\Domain\Tenancy\CurrentOrganization;
use App\Models\BusinessParty;
use App\Models\Customer;
use DomainException;
use Illuminate\Support\Facades\DB;

class CustomerManager
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data): Customer
    {
        $organizationId = $this->currentOrganization->id();

        return DB::transaction(function () use ($data, $organizationId): Customer {
            $party = $this->findAdoptableParty($organizationId, $data);

            if ($party) {
                if (Customer::query()
                    ->forOrganization($organizationId)
                    ->where('business_party_id', $party->getKey())
                    ->lockForUpdate()
                    ->exists()) {
                    throw new DomainException(
                        'Esta identidad comercial ya está registrada como cliente en la organización activa.'
                    );
                }

                $this->enrichMissingPartyData($party, $data);
            } else {
                $this->assertNewIdentityIsSafe($organizationId, $data);
                $party = BusinessParty::query()->create([
                    'organization_id' => $organizationId,
                    ...$this->partyData($data),
                ]);
            }

            return Customer::query()->create([
                'organization_id' => $organizationId,
                'business_party_id' => $party->getKey(),
                'notes' => $data['notes'] ?? null,
                'active' => (bool) $data['active'],
            ])->fresh('party');
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Customer $customer, array $data): Customer
    {
        $organizationId = $this->currentOrganization->id();
        $this->assertBelongsToOrganization($customer, $organizationId);

        return DB::transaction(function () use ($customer, $data, $organizationId): Customer {
            $locked = Customer::query()
                ->forOrganization($organizationId)
                ->whereKey($customer->getKey())
                ->with('party')
                ->lockForUpdate()
                ->firstOrFail();
            $party = BusinessParty::query()
                ->forOrganization($organizationId)
                ->whereKey($locked->business_party_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertUpdatedIdentityIsSafe($organizationId, $party, $data);
            $party->update($this->partyData($data));
            $locked->update([
                'notes' => $data['notes'] ?? null,
                'active' => (bool) $data['active'],
            ]);

            return $locked->fresh('party');
        });
    }

    public function toggleActive(Customer $customer): Customer
    {
        $organizationId = $this->currentOrganization->id();
        $this->assertBelongsToOrganization($customer, $organizationId);

        return DB::transaction(function () use ($customer, $organizationId): Customer {
            $locked = Customer::query()
                ->forOrganization($organizationId)
                ->whereKey($customer->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $locked->update(['active' => ! $locked->active]);
            return $locked->fresh('party');
        });
    }

    /** @param array<string, mixed> $data */
    private function findAdoptableParty(int $organizationId, array $data): ?BusinessParty
    {
        $normalizedTaxId = BusinessParty::normalizeTaxId((string) ($data['tax_id'] ?? ''));
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));

        $taxMatch = $normalizedTaxId !== ''
            ? BusinessParty::query()->forOrganization($organizationId)
                ->where('normalized_tax_id', $normalizedTaxId)
                ->lockForUpdate()->first()
            : null;
        $emailMatches = $email !== ''
            ? BusinessParty::query()->forOrganization($organizationId)
                ->where('email', $email)->lockForUpdate()->get()
            : collect();
        $emailMatch = $emailMatches->count() === 1 ? $emailMatches->first() : null;

        if ($taxMatch && $emailMatch && $taxMatch->getKey() !== $emailMatch->getKey()) {
            throw new DomainException(
                'El documento fiscal y el correo apuntan a identidades comerciales diferentes dentro de la organización activa.'
            );
        }

        $candidate = $taxMatch ?? $emailMatch;
        if (! $candidate) return null;

        if ($candidate->party_type !== $data['party_type']) {
            throw new DomainException('La identidad encontrada posee otro tipo de parte comercial.');
        }

        if ($candidate->normalized_name !== BusinessParty::normalizeName($data['name'])) {
            throw new DomainException(
                'Existe una identidad con el mismo documento o correo, pero con otro nombre. Requiere revisión manual.'
            );
        }

        return $candidate;
    }

    /** @param array<string, mixed> $data */
    private function assertNewIdentityIsSafe(int $organizationId, array $data): void
    {
        if (BusinessParty::query()->forOrganization($organizationId)
            ->where('party_type', $data['party_type'])
            ->where('normalized_name', BusinessParty::normalizeName($data['name']))
            ->lockForUpdate()->exists()) {
            throw new DomainException(
                'Ya existe una parte comercial con un nombre equivalente en la organización activa. Agregá documento fiscal o correo para vincularla sin duplicar.'
            );
        }
    }

    /** @param array<string, mixed> $data */
    private function assertUpdatedIdentityIsSafe(
        int $organizationId,
        BusinessParty $party,
        array $data
    ): void {
        $normalizedTaxId = BusinessParty::normalizeTaxId((string) ($data['tax_id'] ?? ''));

        if ($normalizedTaxId !== '' && BusinessParty::query()
            ->forOrganization($organizationId)->whereKeyNot($party->getKey())
            ->where('normalized_tax_id', $normalizedTaxId)->exists()) {
            throw new DomainException(
                'El documento fiscal ya pertenece a otra identidad comercial de esta organización.'
            );
        }

        if (BusinessParty::query()->forOrganization($organizationId)
            ->whereKeyNot($party->getKey())
            ->where('party_type', $data['party_type'])
            ->where('normalized_name', BusinessParty::normalizeName($data['name']))
            ->exists()) {
            throw new DomainException(
                'Ya existe otra parte comercial con un nombre equivalente en esta organización.'
            );
        }
    }

    /** @param array<string, mixed> $data */
    private function enrichMissingPartyData(BusinessParty $party, array $data): void
    {
        $updates = [];
        foreach (['tax_id', 'email', 'phone', 'website'] as $field) {
            if (blank($party->{$field}) && filled($data[$field] ?? null)) {
                $updates[$field] = $data[$field];
            }
        }
        if ($updates !== []) $party->update($updates);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
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

    private function assertBelongsToOrganization(Customer $customer, int $organizationId): void
    {
        if ((int) $customer->organization_id !== $organizationId) {
            throw new DomainException('El cliente no pertenece a la organización activa.');
        }
    }
}
