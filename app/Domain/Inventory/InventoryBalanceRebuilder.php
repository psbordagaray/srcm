<?php

namespace App\Domain\Inventory;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

final class InventoryBalanceRebuilder
{
    public function __construct(
        private readonly InventoryBalanceCalculator $calculator,
        private readonly InventoryBalanceVerifier $verifier
    ) {
    }

    public function rebuild(
        Organization|int $organization,
        User $actor
    ): InventoryBalanceVerification {
        $organizationId = $organization instanceof Organization
            ? (int) $organization->getKey()
            : $organization;

        return DB::transaction(function () use (
            $organizationId,
            $actor
        ): InventoryBalanceVerification {
            $this->lockActiveOrganization($organizationId);
            $this->guardActor($organizationId, $actor);

            $expected = $this->calculator
                ->expectedForOrganization($organizationId);
            $actual = $this->lockActualBalances($organizationId);
            $now = now();

            foreach ($expected as $key => $position) {
                $current = $actual[$key] ?? null;

                if ($current === null) {
                    DB::table('inventory_balances')->insert([
                        ...$position,
                        'version' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    continue;
                }

                if (
                    $current['base_unit_code']
                        !== $position['base_unit_code']
                    || ! InventoryQuantity::equal(
                        $current['quantity'],
                        $position['quantity']
                    )
                ) {
                    DB::table('inventory_balances')
                        ->where('id', $current['id'])
                        ->update([
                            'quantity' => $position['quantity'],
                            'base_unit_code' =>
                                $position['base_unit_code'],
                            'version' => DB::raw('version + 1'),
                            'updated_at' => $now,
                        ]);
                }

                unset($actual[$key]);
            }

            $unexpectedIds = array_column($actual, 'id');

            if ($unexpectedIds !== []) {
                DB::table('inventory_balances')
                    ->whereIn('id', $unexpectedIds)
                    ->delete();
            }

            $verification = $this->verifier->verify($organizationId);

            if (! $verification->isConsistent()) {
                throw new DomainException(
                    'La reconstrucción no produjo una proyección consistente.'
                );
            }

            return $verification;
        }, 3);
    }

    private function guardActor(int $organizationId, User $actor): void
    {
        if (
            (int) $actor->current_organization_id
                !== $organizationId
        ) {
            throw new DomainException(
                'Solo puede reconstruirse la organización activa del usuario.'
            );
        }

        $membership = OrganizationMembership::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $actor->id)
            ->where('active', true)
            ->lockForUpdate()
            ->first();

        if (! $membership || $membership->role !== UserRole::Admin) {
            throw new DomainException(
                'Solo un administrador activo puede reconstruir saldos.'
            );
        }
    }

    private function lockActiveOrganization(int $organizationId): void
    {
        $organization = DB::table('organizations')
            ->where('id', $organizationId)
            ->where('active', true)
            ->lockForUpdate()
            ->first(['id']);

        if (! $organization) {
            throw new DomainException(
                'La organización no está activa.'
            );
        }
    }

    /**
     * @return array<string, array{
     *     id: int,
     *     quantity: string,
     *     base_unit_code: string
     * }>
     */
    private function lockActualBalances(int $organizationId): array
    {
        $actual = [];

        foreach (
            DB::table('inventory_balances')
                ->where('organization_id', $organizationId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
            as $balance
        ) {
            $key = InventoryBalanceCalculator::key(
                (int) $balance->organization_id,
                (int) $balance->catalog_product_id,
                (int) $balance->inventory_location_id,
                (string) $balance->condition
            );

            $actual[$key] = [
                'id' => (int) $balance->id,
                'quantity' => InventoryQuantity::signed(
                    $balance->quantity
                ),
                'base_unit_code' =>
                    (string) $balance->base_unit_code,
            ];
        }

        return $actual;
    }
}
