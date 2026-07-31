<?php

namespace App\Domain\Inventory;

use App\Models\InventoryMovement;
use App\Models\InventoryMovementLine;
use DomainException;
use Illuminate\Support\Facades\DB;

final class InventoryBalanceProjector
{
    public function apply(InventoryMovement $movement): void
    {
        $this->applyMany([$movement]);
    }

    /**
     * @param iterable<InventoryMovement> $movements
     */
    public function applyMany(iterable $movements): void
    {
        $effects = [];

        foreach ($movements as $movement) {
            foreach ($movement->lines as $line) {
                foreach ($this->effectsForLine($line) as $effect) {
                    $key = $effect['key'];

                    if (! isset($effects[$key])) {
                        $effects[$key] = $effect;

                        continue;
                    }

                    if (
                        $effects[$key]['base_unit_code']
                            !== $effect['base_unit_code']
                    ) {
                        throw new DomainException(
                            'Una confirmación agrupada contiene unidades base incompatibles.'
                        );
                    }

                    $effects[$key]['delta'] = InventoryQuantity::add(
                        $effects[$key]['delta'],
                        $effect['delta']
                    );
                }
            }
        }

        ksort($effects, SORT_STRING);

        foreach ($effects as $effect) {
            if (InventoryQuantity::equal($effect['delta'], '0')) {
                continue;
            }

            $this->applyEffect($effect);
        }
    }

    /**
     * @return array<int, array{
     *     key: string,
     *     organization_id: int,
     *     catalog_product_id: int,
     *     inventory_location_id: int,
     *     condition: string,
     *     base_unit_code: string,
     *     delta: string
     * }>
     */
    private function effectsForLine(
        InventoryMovementLine $line
    ): array {
        $common = [
            'organization_id' => (int) $line->organization_id,
            'catalog_product_id' => (int) $line->catalog_product_id,
            'condition' => $line->condition->value,
            'base_unit_code' => $line->base_unit_code,
        ];
        $effects = [];

        if ($line->source_location_id !== null) {
            $effects[] = $this->effect(
                $common,
                (int) $line->source_location_id,
                InventoryQuantity::negate($line->base_quantity)
            );
        }

        if ($line->destination_location_id !== null) {
            $effects[] = $this->effect(
                $common,
                (int) $line->destination_location_id,
                InventoryQuantity::positive($line->base_quantity)
            );
        }

        return $effects;
    }

    /**
     * @param array{
     *     organization_id: int,
     *     catalog_product_id: int,
     *     condition: string,
     *     base_unit_code: string
     * } $common
     * @return array{
     *     key: string,
     *     organization_id: int,
     *     catalog_product_id: int,
     *     inventory_location_id: int,
     *     condition: string,
     *     base_unit_code: string,
     *     delta: string
     * }
     */
    private function effect(
        array $common,
        int $locationId,
        string $delta
    ): array {
        return [
            'key' => implode(':', [
                $common['organization_id'],
                $common['catalog_product_id'],
                $locationId,
                $common['condition'],
            ]),
            ...$common,
            'inventory_location_id' => $locationId,
            'delta' => $delta,
        ];
    }

    /**
     * @param array{
     *     key: string,
     *     organization_id: int,
     *     catalog_product_id: int,
     *     inventory_location_id: int,
     *     condition: string,
     *     base_unit_code: string,
     *     delta: string
     * } $effect
     */
    private function applyEffect(array $effect): void
    {
        $now = now();
        $dimension = [
            'organization_id' => $effect['organization_id'],
            'catalog_product_id' => $effect['catalog_product_id'],
            'inventory_location_id' =>
                $effect['inventory_location_id'],
            'condition' => $effect['condition'],
        ];

        DB::table('inventory_balances')->insertOrIgnore([
            ...$dimension,
            'quantity' => '0.000000',
            'base_unit_code' => $effect['base_unit_code'],
            'version' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $balance = DB::table('inventory_balances')
            ->where($dimension)
            ->lockForUpdate()
            ->first();

        if (! $balance) {
            throw new DomainException(
                'No se pudo bloquear la posición de inventario.'
            );
        }

        if ($balance->base_unit_code !== $effect['base_unit_code']) {
            throw new DomainException(
                'La unidad base del saldo no coincide con el movimiento.'
            );
        }

        $bindings = [
            $effect['delta'],
            $now,
            $balance->id,
        ];
        $negativeGuard = '';

        if (InventoryQuantity::isNegative($effect['delta'])) {
            $negativeGuard = ' AND quantity + ? >= 0';
            $bindings[] = $effect['delta'];
        }

        $updated = DB::update(
            'UPDATE inventory_balances '
            .'SET quantity = quantity + ?, '
            .'version = version + 1, updated_at = ? '
            .'WHERE id = ?'.$negativeGuard,
            $bindings
        );

        if ($updated !== 1) {
            throw new DomainException(
                'Saldo insuficiente. La operación negativa requiere el flujo explícito de autorización.'
            );
        }
    }
}
