<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\InventoryQuantity;
use App\Domain\Inventory\InventoryNegativeIncidentLifecycle;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\InventoryBaseUnit;
use App\Enums\InventoryCondition;
use App\Enums\InventoryNegativeIncidentStatus;
use App\Enums\InventoryNegativeOverrideStatus;
use App\Enums\InventoryNegativeRequestStatus;
use App\Http\Requests\TransitionInventoryNegativeIncidentRequest;
use App\Models\CatalogProduct;
use App\Models\InventoryLocation;
use App\Models\InventoryNegativeIncident;
use App\Models\InventoryNegativeIncidentLine;
use App\Models\InventoryNegativeOverride;
use App\Models\InventoryNegativeRequest;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

class InventoryNegativeIncidentController extends Controller
{
    public function index(
        Request $request,
        CurrentOrganization $currentOrganization
    ): View {
        $organizationId = $currentOrganization->id($request->user());
        $search = Str::of((string) $request->query('search'))
            ->squish()
            ->limit(100, '')
            ->toString();
        $status = InventoryNegativeIncidentStatus::tryFrom(
            (string) $request->query('status')
        )?->value ?? '';
        $condition = InventoryCondition::tryFrom(
            (string) $request->query('condition')
        )?->value ?? '';
        $attention = (string) $request->query('attention');

        if (! in_array($attention, ['', 'pending', 'regularized'], true)) {
            $attention = '';
        }

        $locations = $this->locations($organizationId);
        $locationId = $this->locationId(
            $request->query('location'),
            $locations
        );
        $summary = $this->summary($organizationId);

        $query = InventoryNegativeIncident::query()
            ->forOrganization($organizationId)
            ->with([
                'requestedBy:id,name',
                'grantedBy:id,name',
                'reviewedBy:id,name',
                'resolvedBy:id,name',
                'lines.product',
                'lines.location:id,name',
            ]);

        if ($status !== '') {
            $query->where('status', $status);
        }

        if ($condition !== '') {
            $query->whereHas(
                'lines',
                fn (Builder $lineQuery): Builder => $lineQuery->where(
                    'condition',
                    $condition
                )
            );
        }

        if ($locationId !== null) {
            $query->whereHas(
                'lines',
                fn (Builder $lineQuery): Builder => $lineQuery->where(
                    'inventory_location_id',
                    $locationId
                )
            );
        }

        if ($attention === 'pending') {
            $query->whereHas(
                'lines',
                fn (Builder $lineQuery): Builder => $lineQuery->where(
                    'pending_deficit',
                    '>',
                    '0'
                )
            );
        } elseif ($attention === 'regularized') {
            $query->whereDoesntHave(
                'lines',
                fn (Builder $lineQuery): Builder => $lineQuery->where(
                    'pending_deficit',
                    '>',
                    '0'
                )
            );
        }

        $this->applySearch($query, $search);

        $incidents = $query
            ->orderByDesc('opened_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $incidents->setCollection(
            $incidents->getCollection()->map(
                fn (InventoryNegativeIncident $incident): array =>
                    $this->incidentForDisplay($incident)
            )
        );

        $statusOptions = collect(
            InventoryNegativeIncidentStatus::cases()
        )->map(fn (InventoryNegativeIncidentStatus $option): array => [
            'value' => $option->value,
            'label' => $this->statusLabel($option),
        ]);
        $conditions = InventoryCondition::cases();

        return view(
            'inventory-negative-incidents.index',
            compact(
                'incidents',
                'locations',
                'statusOptions',
                'conditions',
                'summary',
                'search',
                'status',
                'condition',
                'attention',
                'locationId'
            )
        );
    }

    public function review(
        TransitionInventoryNegativeIncidentRequest $request,
        InventoryNegativeIncident $inventoryNegativeIncident,
        InventoryNegativeIncidentLifecycle $lifecycle
    ): RedirectResponse {
        try {
            $lifecycle->markUnderReview(
                $inventoryNegativeIncident,
                (string) $request->validated('reason'),
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()
                ->withInput()
                ->with('error', $exception->getMessage());
        }

        return back()->with(
            'success',
            'La incidencia quedó marcada en revisión.'
        );
    }

    public function resolve(
        TransitionInventoryNegativeIncidentRequest $request,
        InventoryNegativeIncident $inventoryNegativeIncident,
        InventoryNegativeIncidentLifecycle $lifecycle
    ): RedirectResponse {
        try {
            $lifecycle->resolve(
                $inventoryNegativeIncident,
                (string) $request->validated('reason'),
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()
                ->withInput()
                ->with('error', $exception->getMessage());
        }

        return back()->with(
            'success',
            'La incidencia quedó resuelta administrativamente.'
        );
    }

    /**
     * @return array{
     *     pendingRequests: int,
     *     activeOverrides: int,
     *     activeIncidents: int,
     *     pendingLines: int
     * }
     */
    private function summary(int $organizationId): array
    {
        return [
            'pendingRequests' => InventoryNegativeRequest::query()
                ->forOrganization($organizationId)
                ->where(
                    'status',
                    InventoryNegativeRequestStatus::Pending->value
                )
                ->count(),
            'activeOverrides' => InventoryNegativeOverride::query()
                ->forOrganization($organizationId)
                ->where(
                    'status',
                    InventoryNegativeOverrideStatus::Active->value
                )
                ->count(),
            'activeIncidents' => InventoryNegativeIncident::query()
                ->forOrganization($organizationId)
                ->whereIn('status', [
                    InventoryNegativeIncidentStatus::Open->value,
                    InventoryNegativeIncidentStatus::UnderReview->value,
                ])
                ->count(),
            'pendingLines' => InventoryNegativeIncidentLine::query()
                ->forOrganization($organizationId)
                ->where('pending_deficit', '>', '0')
                ->count(),
        ];
    }

    /**
     * @return Collection<int, array{id: int, name: string}>
     */
    private function locations(int $organizationId): Collection
    {
        $locationIds = InventoryNegativeIncidentLine::query()
            ->forOrganization($organizationId)
            ->select('inventory_location_id');

        return InventoryLocation::query()
            ->forOrganization($organizationId)
            ->whereIn('id', $locationIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (InventoryLocation $location): array => [
                'id' => (int) $location->id,
                'name' => $location->name,
            ]);
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

    private function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $identity = CatalogProduct::normalizeIdentity($search);
        $publicIdSearch = preg_replace(
            '/[^a-zA-Z0-9-]/',
            '',
            $search
        ) ?? '';

        $query->where(function (Builder $incidentQuery) use (
            $identity,
            $publicIdSearch
        ): void {
            if ($publicIdSearch !== '') {
                $incidentQuery->where(
                    'public_id',
                    'like',
                    '%'.$publicIdSearch.'%'
                );
            } else {
                $incidentQuery->whereRaw('1 = 0');
            }

            if ($identity === '') {
                return;
            }

            $incidentQuery
                ->orWhereHas(
                    'lines.product',
                    fn (Builder $productQuery): Builder => $productQuery
                        ->where(
                            'normalized_sku',
                            'like',
                            '%'.$identity.'%'
                        )
                        ->orWhere(
                            'normalized_name',
                            'like',
                            '%'.$identity.'%'
                        )
                )
                ->orWhereHas(
                    'lines.location',
                    fn (Builder $locationQuery): Builder => $locationQuery
                        ->where(
                            'normalized_name',
                            'like',
                            '%'.InventoryLocation::normalizeName(
                                $identity
                            ).'%'
                        )
                );
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function incidentForDisplay(
        InventoryNegativeIncident $incident
    ): array {
        $lines = $incident->lines->map(function ($line): array {
            $scale = (int) ($line->product?->quantity_scale ?? 0);
            $unitCode = (string) $line->base_unit_code;

            return [
                'line' => $line,
                'productName' => $line->product?->name ?? 'Producto ausente',
                'productSku' => $line->product?->sku ?? 'Sin SKU',
                'locationName' => $line->location?->name
                    ?? 'Ubicación ausente',
                'condition' => $line->condition->label(),
                'incrementalDeficit' => $this->quantityForDisplay(
                    (string) $line->incremental_deficit,
                    $scale
                ),
                'pendingDeficit' => $this->quantityForDisplay(
                    (string) $line->pending_deficit,
                    $scale
                ),
                'unit' => InventoryBaseUnit::tryFrom($unitCode)?->label()
                    ?? Str::upper($unitCode),
                'pending' => InventoryQuantity::isPositive(
                    $line->pending_deficit
                ),
            ];
        });

        return [
            'incident' => $incident,
            'shortId' => Str::upper(
                Str::substr((string) $incident->public_id, 0, 8)
            ),
            'statusLabel' => $this->statusLabel($incident->status),
            'statusClass' => $this->statusClass($incident->status),
            'openedAt' => $incident->opened_at->format('d/m/Y H:i'),
            'pendingLines' => $lines->where('pending', true)->count(),
            'canReview' => $incident->status
                === InventoryNegativeIncidentStatus::Open,
            'canResolve' => in_array(
                $incident->status,
                [
                    InventoryNegativeIncidentStatus::Open,
                    InventoryNegativeIncidentStatus::UnderReview,
                ],
                true
            )
                && $lines->where('pending', true)->isEmpty()
                && $incident->regularized_at !== null,
            'lines' => $lines,
        ];
    }

    private function statusLabel(
        InventoryNegativeIncidentStatus $status
    ): string {
        return match ($status) {
            InventoryNegativeIncidentStatus::Open => 'Abierta',
            InventoryNegativeIncidentStatus::UnderReview => 'En revisión',
            InventoryNegativeIncidentStatus::Resolved => 'Resuelta',
        };
    }

    private function statusClass(
        InventoryNegativeIncidentStatus $status
    ): string {
        return match ($status) {
            InventoryNegativeIncidentStatus::Open =>
                'border-red-500/30 bg-red-500/10 text-red-200',
            InventoryNegativeIncidentStatus::UnderReview =>
                'border-amber-500/30 bg-amber-500/10 text-amber-200',
            InventoryNegativeIncidentStatus::Resolved =>
                'border-emerald-500/30 bg-emerald-500/10 text-emerald-200',
        };
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
