<?php

namespace App\Domain\Commerce;

use App\Domain\Tenancy\CurrentOrganization;
use App\Models\Supplier;
use DomainException;
use Illuminate\Support\Facades\DB;

class SupplierManager
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly BusinessPartyIdentityManager $identityManager
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Supplier
    {
        $organizationId = $this->currentOrganization->id();

        return DB::transaction(function () use (
            $data,
            $organizationId
        ): Supplier {
            $party = $this->identityManager
                ->resolveOrCreate($data);

            if (
                Supplier::query()
                    ->forOrganization($organizationId)
                    ->where(
                        'business_party_id',
                        $party->getKey()
                    )
                    ->lockForUpdate()
                    ->exists()
            ) {
                throw new DomainException(
                    'Esta identidad comercial ya está registrada como proveedor en la organización activa.'
                );
            }

            return Supplier::query()
                ->create([
                    'organization_id' => $organizationId,
                    'business_party_id' => $party->getKey(),
                    'notes' => $data['notes'] ?? null,
                    'active' => (bool) $data['active'],
                ])
                ->fresh('party');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(
        Supplier $supplier,
        array $data
    ): Supplier {
        $organizationId = $this->currentOrganization->id();

        $this->assertSupplierBelongsToOrganization(
            $supplier,
            $organizationId
        );

        return DB::transaction(function () use (
            $supplier,
            $data,
            $organizationId
        ): Supplier {
            $locked = Supplier::query()
                ->forOrganization($organizationId)
                ->whereKey($supplier->getKey())
                ->with('party')
                ->lockForUpdate()
                ->firstOrFail();

            $this->identityManager->update(
                $locked->party,
                $data
            );

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
        $organizationId = $this->currentOrganization->id();

        $this->assertSupplierBelongsToOrganization(
            $supplier,
            $organizationId
        );

        return DB::transaction(function () use (
            $supplier,
            $organizationId
        ): Supplier {
            $locked = Supplier::query()
                ->forOrganization($organizationId)
                ->whereKey($supplier->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $locked->update([
                'active' => ! $locked->active,
            ]);

            return $locked->fresh('party');
        });
    }

    private function assertSupplierBelongsToOrganization(
        Supplier $supplier,
        int $organizationId
    ): void {
        if ((int) $supplier->organization_id !== $organizationId) {
            throw new DomainException(
                'El proveedor no pertenece a la organización activa.'
            );
        }
    }
}
