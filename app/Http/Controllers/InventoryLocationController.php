<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\InventoryLocationManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\InventoryLocationType;
use App\Http\Requests\StoreInventoryLocationRequest;
use App\Http\Requests\UpdateInventoryLocationRequest;
use App\Models\InventoryLocation;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class InventoryLocationController extends Controller
{
    public function index(
        Request $request,
        CurrentOrganization $currentOrganization
    ): View {
        $search = trim((string) $request->query('search'));
        $type = (string) $request->query('type');
        $status = (string) $request->query('status');

        if (! InventoryLocationType::tryFrom($type)) {
            $type = '';
        }

        if (! in_array($status, ['', 'active', 'inactive'], true)) {
            $status = '';
        }

        $locations = InventoryLocation::query()
            ->forOrganization($currentOrganization->id())
            ->get();

        $locationRows = $this->hierarchyRows($locations)
            ->filter(function (array $row) use (
                $search,
                $type,
                $status
            ): bool {
                $location = $row['location'];

                if (
                    $search !== ''
                    && ! str_contains(
                        InventoryLocation::normalizeName(
                            $row['path']
                        ),
                        InventoryLocation::normalizeName(
                            $search
                        )
                    )
                ) {
                    return false;
                }

                if (
                    $type !== ''
                    && $location->type->value !== $type
                ) {
                    return false;
                }

                if (
                    $status === 'active'
                    && ! $location->active
                ) {
                    return false;
                }

                if (
                    $status === 'inactive'
                    && $location->active
                ) {
                    return false;
                }

                return true;
            })
            ->values();

        $types = InventoryLocationType::cases();

        return view(
            'inventory-locations.index',
            compact(
                'locationRows',
                'search',
                'type',
                'status',
                'types'
            )
        );
    }

    public function create(
        CurrentOrganization $currentOrganization
    ): View {
        $parentRows = $this->activeParentRows(
            $currentOrganization->id()
        );
        $types = InventoryLocationType::cases();

        return view(
            'inventory-locations.create',
            compact('parentRows', 'types')
        );
    }

    public function store(
        StoreInventoryLocationRequest $request,
        InventoryLocationManager $manager
    ): RedirectResponse {
        try {
            $manager->create($request->validated());
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }

        return redirect()
            ->route('inventory-locations.index')
            ->with(
                'success',
                'Ubicación creada dentro de la organización activa.'
            );
    }

    public function edit(
        InventoryLocation $inventoryLocation,
        CurrentOrganization $currentOrganization
    ): View {
        $this->assertCurrentOrganization(
            $inventoryLocation,
            $currentOrganization
        );

        $excludedIds = $this->descendantIds(
            $inventoryLocation,
            $currentOrganization->id()
        );

        $parentRows = $this->activeParentRows(
            $currentOrganization->id()
        )->reject(
            fn (array $row) => in_array(
                (int) $row['location']->getKey(),
                $excludedIds,
                true
            )
        )->values();

        $types = InventoryLocationType::cases();

        return view(
            'inventory-locations.edit',
            compact(
                'inventoryLocation',
                'parentRows',
                'types'
            )
        );
    }

    public function update(
        UpdateInventoryLocationRequest $request,
        InventoryLocation $inventoryLocation,
        InventoryLocationManager $manager,
        CurrentOrganization $currentOrganization
    ): RedirectResponse {
        $this->assertCurrentOrganization(
            $inventoryLocation,
            $currentOrganization
        );

        try {
            $manager->update(
                $inventoryLocation,
                $request->validated()
            );
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }

        return redirect()
            ->route('inventory-locations.index')
            ->with(
                'success',
                'Ubicación actualizada correctamente.'
            );
    }

    public function toggleActive(
        InventoryLocation $inventoryLocation,
        InventoryLocationManager $manager,
        CurrentOrganization $currentOrganization
    ): RedirectResponse {
        $this->assertCurrentOrganization(
            $inventoryLocation,
            $currentOrganization
        );

        try {
            $updated = $manager->toggleActive(
                $inventoryLocation
            );
        } catch (DomainException $exception) {
            return back()->with(
                'error',
                $exception->getMessage()
            );
        }

        return redirect()
            ->route('inventory-locations.index')
            ->with(
                'success',
                $updated->active
                    ? 'Ubicación activada.'
                    : 'Ubicación inactivada.'
            );
    }

    /**
     * @param Collection<int, InventoryLocation> $locations
     * @return Collection<int, array{
     *     location: InventoryLocation,
     *     depth: int,
     *     path: string
     * }>
     */
    private function hierarchyRows(
        Collection $locations
    ): Collection {
        $children = $locations->groupBy(
            fn (InventoryLocation $location) =>
                $location->parent_id === null
                    ? 'root'
                    : (string) $location->parent_id
        );

        $rows = collect();
        $visited = [];

        $walk = function (
            ?int $parentId,
            int $depth,
            array $parentPath
        ) use (
            &$walk,
            &$visited,
            $children,
            $rows
        ): void {
            $key = $parentId === null
                ? 'root'
                : (string) $parentId;

            $siblings = $children
                ->get($key, collect())
                ->sortBy(
                    fn (InventoryLocation $location) =>
                        ($location->active ? '0-' : '1-')
                        .$location->normalized_name
                );

            foreach ($siblings as $location) {
                $locationId = (int) $location->getKey();

                if (isset($visited[$locationId])) {
                    continue;
                }

                $visited[$locationId] = true;
                $path = [...$parentPath, $location->name];

                $rows->push([
                    'location' => $location,
                    'depth' => $depth,
                    'path' => implode(' / ', $path),
                ]);

                $walk($locationId, $depth + 1, $path);
            }
        };

        $walk(null, 0, []);

        foreach ($locations as $location) {
            if (! isset($visited[$location->getKey()])) {
                $rows->push([
                    'location' => $location,
                    'depth' => 0,
                    'path' => $location->name,
                ]);
            }
        }

        return $rows;
    }

    /**
     * @return Collection<int, array{
     *     location: InventoryLocation,
     *     depth: int,
     *     path: string
     * }>
     */
    private function activeParentRows(
        int $organizationId
    ): Collection {
        return $this->hierarchyRows(
            InventoryLocation::query()
                ->forOrganization($organizationId)
                ->active()
                ->get()
        );
    }

    /**
     * @return array<int, int>
     */
    private function descendantIds(
        InventoryLocation $location,
        int $organizationId
    ): array {
        $locations = InventoryLocation::query()
            ->forOrganization($organizationId)
            ->get(['id', 'parent_id']);

        $excluded = [(int) $location->getKey()];
        $pending = [(int) $location->getKey()];

        while ($pending !== []) {
            $parentId = array_shift($pending);

            foreach (
                $locations->where('parent_id', $parentId)
                as $child
            ) {
                $childId = (int) $child->getKey();

                if (! in_array($childId, $excluded, true)) {
                    $excluded[] = $childId;
                    $pending[] = $childId;
                }
            }
        }

        return $excluded;
    }

    private function assertCurrentOrganization(
        InventoryLocation $location,
        CurrentOrganization $currentOrganization
    ): void {
        abort_unless(
            (int) $location->organization_id
                === $currentOrganization->id(),
            404
        );
    }

    private function domainError(
        DomainException $exception
    ): RedirectResponse {
        $field = str_contains(
            $exception->getMessage(),
            'equivalente'
        )
            ? 'name'
            : 'parent_id';

        return back()
            ->withInput()
            ->withErrors([
                $field => $exception->getMessage(),
            ]);
    }
}
