<?php

namespace App\Domain\Commerce;

use App\Domain\Tenancy\CurrentOrganization;
use App\Models\Customer;
use DomainException;
use Illuminate\Support\Facades\DB;

class CustomerManager
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly BusinessPartyIdentityManager $identityManager
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Customer
    {
        $organizationId = $this->currentOrganization->id();

        return DB::transaction(function () use (
            $data,
            $organizationId
        ): Customer {
            $party = $this->identityManager
                ->resolveOrCreate($data);

            if (
                Customer::query()
                    ->forOrganization($organizationId)
                    ->where(
                        'business_party_id',
                        $party->getKey()
                    )
                    ->lockForUpdate()
                    ->exists()
            ) {
                throw new DomainException(
                    'Esta identidad comercial ya está registrada como cliente en la organización activa.'
                );
            }

            return Customer::query()->create([
                'organization_id' => $organizationId,
                'business_party_id' => $party->getKey(),
                'notes' => $data['notes'] ?? null,
                'active' => (bool) $data['active'],
            ])->fresh('party');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(
        Customer $customer,
        array $data
    ): Customer {
        $organizationId = $this->currentOrganization->id();

        $this->assertBelongsToOrganization(
            $customer,
            $organizationId
        );

        return DB::transaction(function () use (
            $customer,
            $data,
            $organizationId
        ): Customer {
            $locked = Customer::query()
                ->forOrganization($organizationId)
                ->whereKey($customer->getKey())
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

    public function toggleActive(Customer $customer): Customer
    {
        $organizationId = $this->currentOrganization->id();

        $this->assertBelongsToOrganization(
            $customer,
            $organizationId
        );

        return DB::transaction(function () use (
            $customer,
            $organizationId
        ): Customer {
            $locked = Customer::query()
                ->forOrganization($organizationId)
                ->whereKey($customer->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $locked->update([
                'active' => ! $locked->active,
            ]);

            return $locked->fresh('party');
        });
    }

    private function assertBelongsToOrganization(
        Customer $customer,
        int $organizationId
    ): void {
        if ((int) $customer->organization_id !== $organizationId) {
            throw new DomainException(
                'El cliente no pertenece a la organización activa.'
            );
        }
    }
}
