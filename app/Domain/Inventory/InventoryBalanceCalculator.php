<?php

namespace App\Domain\Inventory;

use DomainException;
use Illuminate\Support\Facades\DB;

final class InventoryBalanceCalculator
{
    /**
     * @return array<string, array{
     *     organization_id: int,
     *     catalog_product_id: int,
     *     inventory_location_id: int,
     *     condition: string,
     *     quantity: string,
     *     base_unit_code: string
     * }>
     */
    public function expectedForOrganization(int $organizationId): array
    {
        $positions = [];

        $lines = DB::table('inventory_movement_lines as lines')
            ->join(
                'inventory_movements as movements',
                function ($join): void {
                    $join->on(
                        'movements.id',
                        '=',
                        'lines.inventory_movement_id'
                    )->on(
                        'movements.organization_id',
                        '=',
                        'lines.organization_id'
                    );
                }
            )
            ->where('movements.organization_id', $organizationId)
            ->where('movements.status', 'confirmed')
            ->orderBy('movements.id')
            ->orderBy('lines.sequence')
            ->get([
                'lines.organization_id',
                'lines.catalog_product_id',
                'lines.condition',
                'lines.source_location_id',
                'lines.destination_location_id',
                'lines.base_quantity',
                'lines.base_unit_code',
            ]);

        foreach ($lines as $line) {
            if ($line->source_location_id !== null) {
                $this->accumulate(
                    $positions,
                    (int) $line->organization_id,
                    (int) $line->catalog_product_id,
                    (int) $line->source_location_id,
                    (string) $line->condition,
                    (string) $line->base_unit_code,
                    InventoryQuantity::negate($line->base_quantity)
                );
            }

            if ($line->destination_location_id !== null) {
                $this->accumulate(
                    $positions,
                    (int) $line->organization_id,
                    (int) $line->catalog_product_id,
                    (int) $line->destination_location_id,
                    (string) $line->condition,
                    (string) $line->base_unit_code,
                    InventoryQuantity::positive($line->base_quantity)
                );
            }
        }

        ksort($positions, SORT_STRING);

        return $positions;
    }

    public static function key(
        int $organizationId,
        int $productId,
        int $locationId,
        string $condition
    ): string {
        return implode(':', [
            $organizationId,
            $productId,
            $locationId,
            $condition,
        ]);
    }

    /**
     * @param array<string, array{
     *     organization_id: int,
     *     catalog_product_id: int,
     *     inventory_location_id: int,
     *     condition: string,
     *     quantity: string,
     *     base_unit_code: string
     * }> $positions
     */
    private function accumulate(
        array &$positions,
        int $organizationId,
        int $productId,
        int $locationId,
        string $condition,
        string $baseUnitCode,
        string $delta
    ): void {
        $key = self::key(
            $organizationId,
            $productId,
            $locationId,
            $condition
        );

        $position = $positions[$key] ?? [
            'organization_id' => $organizationId,
            'catalog_product_id' => $productId,
            'inventory_location_id' => $locationId,
            'condition' => $condition,
            'quantity' => '0.000000',
            'base_unit_code' => $baseUnitCode,
        ];

        if ($position['base_unit_code'] !== $baseUnitCode) {
            throw new DomainException(
                'El libro contiene unidades base incompatibles para una misma posición.'
            );
        }

        $position['quantity'] = InventoryQuantity::add(
            $position['quantity'],
            $delta
        );

        $positions[$key] = $position;
    }
}
