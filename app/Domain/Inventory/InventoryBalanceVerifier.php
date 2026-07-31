<?php

namespace App\Domain\Inventory;

use Illuminate\Support\Facades\DB;

final class InventoryBalanceVerifier
{
    public function __construct(
        private readonly InventoryBalanceCalculator $calculator
    ) {
    }

    public function verify(int $organizationId): InventoryBalanceVerification
    {
        $expected = $this->calculator
            ->expectedForOrganization($organizationId);
        $actual = $this->actualForOrganization($organizationId);
        $differences = [];

        foreach ($expected as $key => $expectedPosition) {
            $actualPosition = $actual[$key] ?? null;

            if ($actualPosition === null) {
                $differences[] = $this->difference(
                    'missing_balance',
                    $key,
                    $expectedPosition,
                    null
                );

                continue;
            }

            if (
                $expectedPosition['base_unit_code']
                    !== $actualPosition['base_unit_code']
            ) {
                $differences[] = $this->difference(
                    'unit_mismatch',
                    $key,
                    $expectedPosition,
                    $actualPosition
                );
            }

            if (! InventoryQuantity::equal(
                $expectedPosition['quantity'],
                $actualPosition['quantity']
            )) {
                $differences[] = $this->difference(
                    'quantity_mismatch',
                    $key,
                    $expectedPosition,
                    $actualPosition
                );
            }

            unset($actual[$key]);
        }

        foreach ($actual as $key => $actualPosition) {
            $differences[] = $this->difference(
                'unexpected_balance',
                $key,
                null,
                $actualPosition
            );
        }

        usort(
            $differences,
            fn (array $left, array $right): int =>
                [$left['key'], $left['type']]
                    <=> [$right['key'], $right['type']]
        );

        return new InventoryBalanceVerification(
            $organizationId,
            $differences
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function actualForOrganization(int $organizationId): array
    {
        $actual = [];

        foreach (
            DB::table('inventory_balances')
                ->where('organization_id', $organizationId)
                ->orderBy('id')
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
                'organization_id' => (int) $balance->organization_id,
                'catalog_product_id' =>
                    (int) $balance->catalog_product_id,
                'inventory_location_id' =>
                    (int) $balance->inventory_location_id,
                'condition' => (string) $balance->condition,
                'quantity' => InventoryQuantity::signed(
                    $balance->quantity
                ),
                'base_unit_code' =>
                    (string) $balance->base_unit_code,
                'version' => (int) $balance->version,
            ];
        }

        return $actual;
    }

    /**
     * @param array<string, mixed>|null $expected
     * @param array<string, mixed>|null $actual
     * @return array{
     *     type: string,
     *     key: string,
     *     expected: array<string, mixed>|null,
     *     actual: array<string, mixed>|null
     * }
     */
    private function difference(
        string $type,
        string $key,
        ?array $expected,
        ?array $actual
    ): array {
        return compact('type', 'key', 'expected', 'actual');
    }
}
