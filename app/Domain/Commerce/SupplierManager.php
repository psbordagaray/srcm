<?php

namespace App\Domain\Commerce;

use App\Models\BusinessParty;
use App\Models\Supplier;
use DomainException;
use Illuminate\Support\Facades\DB;

class SupplierManager
{
    /**
     * @param array{
     *     party_type: string,
     *     name: string,
     *     tax_id: ?string,
     *     email: ?string,
     *     phone: ?string,
     *     website: ?string,
     *     notes: ?string,
     *     active: bool|int|string
     * } $data
     */
    public function create(array $data): Supplier
    {
        return DB::transaction(function () use ($data): Supplier {
            $party = $this->findAdoptableParty($data);

            if ($party) {
                if (
                    Supplier::query()
                        ->where(
                            'business_party_id',
                            $party->getKey()
                        )
                        ->lockForUpdate()
                        ->exists()
                ) {
                    throw new DomainException(
                        'Esta identidad comercial ya está registrada como proveedor.'
                    );
                }

                $this->enrichMissingPartyData($party, $data);
            } else {
                $this->assertNewIdentityIsSafe($data);

                $party = BusinessParty::query()->create(
                    $this->partyData($data)
                );
            }

            return Supplier::query()
                ->create([
                    'business_party_id' => $party->getKey(),
                    'notes' => $data['notes'] ?? null,
                    'active' => (bool) $data['active'],
                ])
                ->fresh('party');
        });
    }

    /**
     * @param array{
     *     party_type: string,
     *     name: string,
     *     tax_id: ?string,
     *     email: ?string,
     *     phone: ?string,
     *     website: ?string,
     *     notes: ?string,
     *     active: bool|int|string
     * } $data
     */
    public function update(
        Supplier $supplier,
        array $data
    ): Supplier {
        return DB::transaction(function () use (
            $supplier,
            $data
        ): Supplier {
            $locked = Supplier::query()
                ->whereKey($supplier->getKey())
                ->with('party')
                ->lockForUpdate()
                ->firstOrFail();

            $party = BusinessParty::query()
                ->whereKey($locked->business_party_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertUpdatedIdentityIsSafe(
                $party,
                $data
            );

            $party->update($this->partyData($data));

            $locked->update([
                'notes' => $data['notes'] ?? null,
                'active' => (bool) $data['active'],
            ]);

            return $locked->fresh('party');
        });
    }

    public function toggleActive(
        Supplier $supplier
    ): Supplier {
        return DB::transaction(function () use (
            $supplier
        ): Supplier {
            $locked = Supplier::query()
                ->whereKey($supplier->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $locked->update([
                'active' => ! $locked->active,
            ]);

            return $locked->fresh('party');
        });
    }

    /**
     * @param array<string, mixed> $data
     */
    private function findAdoptableParty(
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
                ->where(
                    'normalized_tax_id',
                    $normalizedTaxId
                )
                ->lockForUpdate()
                ->first()
            : null;

        $emailMatches = $email !== ''
            ? BusinessParty::query()
                ->where('email', $email)
                ->lockForUpdate()
                ->get()
            : collect();

        $emailMatch = $emailMatches->count() === 1
            ? $emailMatches->first()
            : null;

        if (
            $taxMatch
            && $emailMatch
            && $taxMatch->getKey() !== $emailMatch->getKey()
        ) {
            throw new DomainException(
                'El documento fiscal y el correo apuntan a identidades comerciales diferentes.'
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
     * @param array<string, mixed> $data
     */
    private function assertNewIdentityIsSafe(
        array $data
    ): void {
        $probableMatches = BusinessParty::query()
            ->where('party_type', $data['party_type'])
            ->where(
                'normalized_name',
                BusinessParty::normalizeName($data['name'])
            )
            ->lockForUpdate()
            ->get();

        if ($probableMatches->isNotEmpty()) {
            throw new DomainException(
                'Ya existe una parte comercial con un nombre equivalente. Agregá documento fiscal o correo para vincularla sin duplicar.'
            );
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function assertUpdatedIdentityIsSafe(
        BusinessParty $party,
        array $data
    ): void {
        $normalizedTaxId = BusinessParty::normalizeTaxId(
            (string) ($data['tax_id'] ?? '')
        );

        if (
            $normalizedTaxId !== ''
            && BusinessParty::query()
                ->whereKeyNot($party->getKey())
                ->where(
                    'normalized_tax_id',
                    $normalizedTaxId
                )
                ->exists()
        ) {
            throw new DomainException(
                'El documento fiscal ya pertenece a otra identidad comercial.'
            );
        }

        if (
            BusinessParty::query()
                ->whereKeyNot($party->getKey())
                ->where('party_type', $data['party_type'])
                ->where(
                    'normalized_name',
                    BusinessParty::normalizeName($data['name'])
                )
                ->exists()
        ) {
            throw new DomainException(
                'Ya existe otra parte comercial con un nombre equivalente.'
            );
        }
    }

    /**
     * @param array<string, mixed> $data
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
     * @param array<string, mixed> $data
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
}
