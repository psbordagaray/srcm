<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\InventoryAvailabilityPosition;
use App\Domain\Inventory\InventoryAvailabilityReader;
use App\Domain\Inventory\InventoryQuantity;
use App\Enums\InventoryBaseUnit;
use App\Enums\InventoryCondition;
use App\Models\CatalogProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

class InventoryAvailabilityController extends Controller
{
    public function index(
        Request $request,
        InventoryAvailabilityReader $reader
    ): View {
        $search = Str::of((string) $request->query('search'))
            ->squish()
            ->limit(100, '')
            ->toString();

        $condition = InventoryCondition::tryFrom(
            (string) $request->query('condition')
        )?->value ?? '';

        $status = (string) $request->query('status');

        if (
            ! in_array(
                $status,
                ['', 'available', 'deficit', 'zero', 'inactive'],
                true
            )
        ) {
            $status = '';
        }

        $positions = $reader->positions($request->user());
        $locations = $this->locations($positions);
        $locationId = $this->locationId(
            $request->query('location'),
            $locations
        );

        $summary = [
            'positions' => $positions->count(),
            'available' => $positions
                ->filter(
                    fn (InventoryAvailabilityPosition $position): bool =>
                        InventoryQuantity::isPositive(
                            $position->availableQuantity
                        )
                )->count(),
            'deficits' => $positions
                ->filter(
                    fn (InventoryAvailabilityPosition $position): bool =>
                        $position->hasDeficit()
                )->count(),
            'locations' => $locations->count(),
        ];

        $rows = $positions
            ->filter(function (
                InventoryAvailabilityPosition $position
            ) use (
                $search,
                $condition,
                $status,
                $locationId
            ): bool {
                if (
                    $search !== ''
                    && ! str_contains(
                        CatalogProduct::normalizeIdentity(
                            $position->productSku.' '
                            .$position->productName.' '
                            .$position->locationName
                        ),
                        CatalogProduct::normalizeIdentity($search)
                    )
                ) {
                    return false;
                }

                if (
                    $condition !== ''
                    && $position->condition->value !== $condition
                ) {
                    return false;
                }

                if (
                    $locationId !== null
                    && $position->inventoryLocationId !== $locationId
                ) {
                    return false;
                }

                return match ($status) {
                    'available' => InventoryQuantity::isPositive(
                        $position->availableQuantity
                    ),
                    'deficit' => $position->hasDeficit(),
                    'zero' => InventoryQuantity::equal(
                        $position->physicalQuantity,
                        '0'
                    ),
                    'inactive' => ! $position->productActive
                        || ! $position->locationActive,
                    default => true,
                };
            })
            ->map(fn (InventoryAvailabilityPosition $position): array => [
                'position' => $position,
                'physical' => $this->quantityForDisplay(
                    $position->physicalQuantity,
                    $position->quantityScale
                ),
                'available' => $this->quantityForDisplay(
                    $position->availableQuantity,
                    $position->quantityScale
                ),
                'deficit' => $this->quantityForDisplay(
                    $position->deficitQuantity,
                    $position->quantityScale
                ),
                'unit' => InventoryBaseUnit::tryFrom(
                    $position->baseUnitCode
                )?->label() ?? Str::upper($position->baseUnitCode),
            ])
            ->values();

        $conditions = InventoryCondition::cases();

        return view(
            'inventory-availability.index',
            compact(
                'rows',
                'positions',
                'locations',
                'conditions',
                'summary',
                'search',
                'condition',
                'status',
                'locationId'
            )
        );
    }

    /**
     * @param Collection<int, InventoryAvailabilityPosition> $positions
     * @return Collection<int, array{id: int, name: string}>
     */
    private function locations(Collection $positions): Collection
    {
        return $positions
            ->map(
                fn (InventoryAvailabilityPosition $position): array => [
                    'id' => $position->inventoryLocationId,
                    'name' => $position->locationName,
                ]
            )
            ->unique('id')
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    /**
     * @param Collection<int, array{id: int, name: string}> $locations
     */
    private function locationId(
        mixed $requestedLocation,
        Collection $locations
    ): ?int {
        $requestedLocation = trim((string) $requestedLocation);

        if (
            $requestedLocation === ''
            || preg_match('/^[1-9]\d*$/', $requestedLocation) !== 1
        ) {
            return null;
        }

        $locationId = (int) $requestedLocation;

        return $locations->contains('id', $locationId)
            ? $locationId
            : null;
    }

    private function quantityForDisplay(
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
