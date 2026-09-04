<?php

namespace App\Domain\Commerce;

use App\Domain\Inventory\InventoryQuantity;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\InventoryCondition;
use App\Models\CatalogProduct;
use App\Models\InventoryLocation;
use App\Models\User;
use Illuminate\Support\Str;

final class CommerceSalePolicyGuard
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly CommercialAvailabilityReader $availability
    ) {
    }

    /**
     * HTTP/UI product lines deliberately carry no client-controlled price.
     * The checkout manager resolves the organization price while holding the
     * organization transaction lock.
     *
     * @param list<array<string, mixed>> $validatedLines
     * @return list<CommerceProductLineData>
     */
    public function productLines(array $validatedLines): array
    {
        return collect($validatedLines)
            ->map(
                fn (array $line): CommerceProductLineData =>
                    new CommerceProductLineData(
                        catalogProductId:
                            (int) $line['catalog_product_id'],
                        sourceLocationId:
                            (int) $line['source_location_id'],
                        condition: InventoryCondition::from(
                            (string) $line['condition']
                        ),
                        quantity: (string) $line['quantity'],
                        unitPriceMinor: null
                    )
            )
            ->values()
            ->all();
    }

    /** @param list<array<string, mixed>> $validatedLines */
    public function stockShortageMessage(
        array $validatedLines,
        User $actor
    ): ?string {
        if ($validatedLines === []) {
            return null;
        }

        $organizationId = $this->currentOrganization->id($actor);
        $positions = $this->availability->positions($actor);
        $requested = [];

        foreach ($validatedLines as $line) {
            $key = $this->key(
                (int) $line['catalog_product_id'],
                (int) $line['source_location_id'],
                (string) $line['condition']
            );
            $quantity = InventoryQuantity::positive(
                (string) $line['quantity']
            );

            if (! isset($requested[$key])) {
                $requested[$key] = [
                    'catalog_product_id' =>
                        (int) $line['catalog_product_id'],
                    'source_location_id' =>
                        (int) $line['source_location_id'],
                    'condition' => (string) $line['condition'],
                    'quantity' => $quantity,
                ];

                continue;
            }

            $requested[$key]['quantity'] = InventoryQuantity::add(
                $requested[$key]['quantity'],
                $quantity
            );
        }

        foreach ($requested as $row) {
            $position = $positions->first(
                fn ($position): bool =>
                    $position->catalogProductId
                        === $row['catalog_product_id']
                    && $position->inventoryLocationId
                        === $row['source_location_id']
                    && $position->condition->value
                        === $row['condition']
            );
            $available = $position?->commercialAvailableQuantity
                ?? '0.000000';

            if (! InventoryQuantity::isNegative(
                InventoryQuantity::subtract(
                    $available,
                    $row['quantity']
                )
            )) {
                continue;
            }

            $product = CatalogProduct::query()
                ->whereKey($row['catalog_product_id'])
                ->first();
            $location = InventoryLocation::query()
                ->forOrganization($organizationId)
                ->whereKey($row['source_location_id'])
                ->first();
            $scale = (int) ($product?->quantity_scale ?? 0);
            $missing = InventoryQuantity::subtract(
                $row['quantity'],
                $available
            );

            return sprintf(
                'Stock insuficiente para "%s" en %s. Disponibles: %s; solicitadas en esta venta: %s; faltante: %s.',
                $product?->name ?? 'Producto',
                $location?->name ?? 'ubicación seleccionada',
                $this->formatQuantity($available, $scale),
                $this->formatQuantity(
                    $row['quantity'],
                    $scale
                ),
                $this->formatQuantity($missing, $scale)
            );
        }

        return null;
    }

    /**
     * @return array<string, array{
     *   quantity: string,
     *   display: string,
     *   location: string
     * }>
     */
    public function availabilityMatrix(User $actor): array
    {
        $matrix = [];

        foreach ($this->availability->positions($actor) as $position) {
            $matrix[$this->key(
                $position->catalogProductId,
                $position->inventoryLocationId,
                $position->condition->value
            )] = [
                'quantity' => $position->commercialAvailableQuantity,
                'display' => $this->formatQuantity(
                    $position->commercialAvailableQuantity,
                    $position->quantityScale
                ),
                'location' => $position->locationName,
            ];
        }

        return $matrix;
    }

    private function key(
        int $productId,
        int $locationId,
        string $condition
    ): string {
        return implode(':', [
            $productId,
            $locationId,
            Str::lower(trim($condition)),
        ]);
    }

    private function formatQuantity(
        string $quantity,
        int $scale
    ): string {
        $scale = max(0, min(InventoryQuantity::SCALE, $scale));
        $negative = str_starts_with($quantity, '-')
            && ! InventoryQuantity::equal($quantity, '0');
        $unsigned = ltrim($quantity, '+-');
        [$integer, $fraction] = array_pad(
            explode('.', $unsigned, 2),
            2,
            ''
        );

        $integer = ltrim($integer, '0');
        $integer = $integer === '' ? '0' : $integer;
        $integer = preg_replace(
            '/\B(?=(\d{3})+(?!\d))/',
            '.',
            $integer
        ) ?? $integer;

        if ($scale === 0) {
            return ($negative ? '-' : '').$integer;
        }

        $fraction = substr(
            str_pad($fraction, $scale, '0'),
            0,
            $scale
        );

        return ($negative ? '-' : '').$integer.','.$fraction;
    }
}
