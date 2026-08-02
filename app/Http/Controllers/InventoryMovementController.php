<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\InventoryMovementConfirmer;
use App\Domain\Inventory\InventoryMovementCorrector;
use App\Domain\Inventory\InventoryMovementCreator;
use App\Domain\Inventory\InventoryMovementDraftData;
use App\Domain\Inventory\InventoryMovementLineData;
use App\Domain\Inventory\InventoryQuantity;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\InventoryBaseUnit;
use App\Enums\InventoryCondition;
use App\Enums\InventoryMovementStatus;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryNegativeOverrideStatus;
use App\Enums\InventoryNegativeRequestStatus;
use App\Http\Requests\ConfirmInventoryMovementRequest;
use App\Http\Requests\CorrectInventoryMovementRequest;
use App\Http\Requests\StoreInventoryMovementRequest;
use App\Models\CatalogProduct;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementLine;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class InventoryMovementController extends Controller
{
    public function index(
        Request $request,
        CurrentOrganization $currentOrganization
    ): View {
        $organizationId = $currentOrganization->id($request->user());
        $search = Str::of((string) $request->query('search'))
            ->squish()->limit(100, '')->toString();
        $type = InventoryMovementType::tryFrom(
            (string) $request->query('type')
        )?->value ?? '';
        $status = InventoryMovementStatus::tryFrom(
            (string) $request->query('status')
        )?->value ?? '';
        $role = $currentOrganization->roleFor($request->user());

        $query = InventoryMovement::query()
            ->forOrganization($organizationId)
            ->with([
                'createdBy:id,name',
                'confirmedBy:id,name',
                'lines.product:id,sku,name,base_unit_code,quantity_scale',
                'lines.sourceLocation:id,name',
                'lines.destinationLocation:id,name',
                'negativeAuthorizationRequest.override',
                'reversal:id,public_id,reverses_movement_id',
                'replacement:id,public_id,replaces_movement_id',
                'reverses:id,public_id',
                'replaces:id,public_id',
            ]);

        if ($type !== '') {
            $query->where('type', $type);
        }

        if ($status !== '') {
            $query->where('status', $status);
        }

        $this->applySearch($query, $search);

        $movements = $query
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $movements->setCollection(
            $movements->getCollection()->map(
                function (InventoryMovement $movement) use (
                    $role,
                    $request
                ): array {
                    $authorization =
                        $movement->negativeAuthorizationRequest;
                    $override = $authorization?->override;
                    $activeOverride = $override?->status
                        === InventoryNegativeOverrideStatus::Active;
                    $canConfirm = $movement->status
                        === InventoryMovementStatus::Draft
                        && ($role?->canConfirmInventoryMovement(
                            $movement->type
                        ) ?? false);
                    $canRequest = $canConfirm
                        && ($role?->canRequestInventoryNegative() ?? false)
                        && $movement->type->requiresSource()
                        && (int) $movement->created_by_user_id
                            === (int) $request->user()->id
                        && (
                            $authorization === null
                            || in_array($authorization->status, [
                                InventoryNegativeRequestStatus::Rejected,
                                InventoryNegativeRequestStatus::Invalidated,
                            ], true)
                        );

                    return [
                        'movement' => $movement,
                        'shortId' => Str::upper(Str::substr(
                            (string) $movement->public_id,
                            0,
                            8
                        )),
                        'statusClass' => $this->statusClass(
                            $movement->status
                        ),
                        'canConfirm' => $canConfirm && ! $activeOverride,
                        'canRequestNegative' => $canRequest,
                        'negativeAuthorization' => $authorization,
                        'canConfirmWithOverride' => $canConfirm
                            && $activeOverride
                            && (int) $override->authorized_user_id
                                === (int) $request->user()->id,
                        'canCorrect' => ($role?->canCorrectInventory()
                            ?? false)
                            && $movement->status
                                === InventoryMovementStatus::Confirmed
                            && $movement->type
                                !== InventoryMovementType::Reversal
                            && $movement->reversal === null
                            && $movement->replacement === null,
                        'lines' => $movement->lines->map(
                            fn ($line): array =>
                                $this->lineForDisplay($line)
                        ),
                    ];
                }
            )
        );

        $summary = [
            'drafts' => InventoryMovement::query()
                ->forOrganization($organizationId)
                ->where('status', InventoryMovementStatus::Draft->value)
                ->count(),
            'confirmed' => InventoryMovement::query()
                ->forOrganization($organizationId)
                ->where(
                    'status',
                    InventoryMovementStatus::Confirmed->value
                )->count(),
            'today' => InventoryMovement::query()
                ->forOrganization($organizationId)
                ->whereDate('effective_at', today())
                ->count(),
        ];
        $types = InventoryMovementType::cases();
        $statuses = InventoryMovementStatus::cases();

        return view('inventory-movements.index', compact(
            'movements',
            'summary',
            'types',
            'statuses',
            'search',
            'type',
            'status'
        ));
    }

    public function create(
        Request $request,
        CurrentOrganization $currentOrganization
    ): View {
        $organizationId = $currentOrganization->id($request->user());
        $role = $currentOrganization->roleFor($request->user());
        $types = collect(InventoryMovementType::cases())
            ->filter(
                fn (InventoryMovementType $type): bool =>
                    $type !== InventoryMovementType::Reversal
                    && ($role?->canDraftInventoryMovement($type) ?? false)
            )->values();
        $conditions = InventoryCondition::cases();
        $products = CatalogProduct::query()
            ->where('active', true)
            ->orderBy('name')
            ->get([
                'id',
                'sku',
                'name',
                'base_unit_code',
                'quantity_scale',
            ]);
        $locations = InventoryLocation::query()
            ->forOrganization($organizationId)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name']);
        $idempotencyKey = 'inventory-ui:'.Str::uuid();
        $movementRules = collect(InventoryMovementType::cases())
            ->mapWithKeys(fn (InventoryMovementType $type): array => [
                $type->value => [
                    'allowsSource' => $type->allowsSource(),
                    'allowsDestination' => $type->allowsDestination(),
                    'requiresSource' => $type->requiresSource(),
                    'requiresDestination' => $type->requiresDestination(),
                ],
            ]);

        return view('inventory-movements.create', compact(
            'types',
            'conditions',
            'products',
            'locations',
            'idempotencyKey',
            'movementRules'
        ));
    }

    public function store(
        StoreInventoryMovementRequest $request,
        InventoryMovementCreator $creator
    ): RedirectResponse {
        $validated = $request->validated();
        $products = CatalogProduct::query()
            ->where('active', true)
            ->whereIn(
                'id',
                collect($validated['lines'])
                    ->pluck('catalog_product_id')
                    ->unique()
            )
            ->get(['id', 'base_unit_code'])
            ->keyBy('id');

        try {
            $movement = $creator->create(
                new InventoryMovementDraftData(
                    type: InventoryMovementType::from($validated['type']),
                    effectiveAt: CarbonImmutable::parse(
                        $validated['effective_at'],
                        config('app.timezone')
                    ),
                    reason: $validated['reason'],
                    idempotencyKey: $validated['idempotency_key'],
                    lines: collect($validated['lines'])
                        ->map(function (array $line) use (
                            $products
                        ): InventoryMovementLineData {
                            $product = $products->get(
                                $line['catalog_product_id']
                            );

                            if (! $product) {
                                throw new DomainException(
                                    'El producto dejó de estar disponible.'
                                );
                            }

                            return new InventoryMovementLineData(
                                catalogProductId: (int) $product->id,
                                condition: InventoryCondition::from(
                                    $line['condition']
                                ),
                                enteredQuantity:
                                    $line['entered_quantity'],
                                enteredUnitCode:
                                    $product->base_unit_code,
                                sourceLocationId:
                                    $line['source_location_id'] ?? null,
                                destinationLocationId:
                                    $line['destination_location_id']
                                        ?? null,
                                notes: $line['notes'] ?? null
                            );
                        })->all(),
                    sourceType: 'manual_ui',
                    sourceReference:
                        $validated['source_reference'] ?? null,
                    metadata: ['created_from' => 'inventory_movements_ui']
                ),
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()
                ->withInput()
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('inventory-movements.index')
            ->with(
                'success',
                'Borrador #'.Str::upper(Str::substr(
                    (string) $movement->public_id,
                    0,
                    8
                )).' creado correctamente.'
            );
    }

    public function confirm(
        ConfirmInventoryMovementRequest $request,
        InventoryMovement $inventoryMovement,
        InventoryMovementConfirmer $confirmer
    ): RedirectResponse {
        try {
            $confirmer->confirm($inventoryMovement, $request->user());
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with(
            'success',
            'Movimiento confirmado y proyectado correctamente.'
        );
    }

    public function correction(
        Request $request,
        InventoryMovement $inventoryMovement,
        CurrentOrganization $currentOrganization
    ): View|RedirectResponse {
        $organizationId = $currentOrganization->id($request->user());
        $inventoryMovement->load([
            'lines.product:id,sku,name,base_unit_code,quantity_scale',
            'lines.sourceLocation:id,name',
            'lines.destinationLocation:id,name',
            'reversal:id,public_id,reverses_movement_id',
            'replacement:id,public_id,replaces_movement_id',
        ]);

        if (
            $inventoryMovement->status
                !== InventoryMovementStatus::Confirmed
            || $inventoryMovement->type
                === InventoryMovementType::Reversal
            || $inventoryMovement->reversal !== null
            || $inventoryMovement->replacement !== null
        ) {
            return redirect()
                ->route('inventory-movements.index', [
                    'search' => $inventoryMovement->public_id,
                ])
                ->with(
                    'error',
                    'El movimiento no admite una nueva corrección.'
                );
        }

        $role = $currentOrganization->roleFor($request->user());
        $types = collect(InventoryMovementType::cases())
            ->filter(
                fn (InventoryMovementType $type): bool =>
                    $type !== InventoryMovementType::Reversal
                    && ($role?->canDraftInventoryMovement($type) ?? false)
            )->values();
        $conditions = InventoryCondition::cases();
        $products = CatalogProduct::query()
            ->where('active', true)
            ->orderBy('name')
            ->get([
                'id',
                'sku',
                'name',
                'base_unit_code',
                'quantity_scale',
            ]);
        $locations = InventoryLocation::query()
            ->forOrganization($organizationId)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name']);
        $idempotencyKey = 'inventory-ui:'.Str::uuid();
        $movementRules = collect(InventoryMovementType::cases())
            ->mapWithKeys(fn (InventoryMovementType $type): array => [
                $type->value => [
                    'allowsSource' => $type->allowsSource(),
                    'allowsDestination' => $type->allowsDestination(),
                    'requiresSource' => $type->requiresSource(),
                    'requiresDestination' => $type->requiresDestination(),
                ],
            ]);
        $correctionOriginal = $inventoryMovement;
        $correctionLines = $inventoryMovement->lines
            ->map(fn (InventoryMovementLine $line): array => [
                'catalog_product_id' => $line->catalog_product_id,
                'condition' => $line->condition->value,
                'entered_quantity' =>
                    $line->getRawOriginal('entered_quantity'),
                'source_location_id' => $line->source_location_id,
                'destination_location_id' =>
                    $line->destination_location_id,
                'notes' => $line->notes,
            ])->all();

        return view('inventory-movements.create', compact(
            'types',
            'conditions',
            'products',
            'locations',
            'idempotencyKey',
            'movementRules',
            'correctionOriginal',
            'correctionLines'
        ));
    }

    public function correct(
        CorrectInventoryMovementRequest $request,
        InventoryMovement $inventoryMovement,
        InventoryMovementCreator $creator,
        InventoryMovementCorrector $corrector
    ): RedirectResponse {
        $validated = $request->validated();
        $products = CatalogProduct::query()
            ->where('active', true)
            ->whereIn(
                'id',
                collect($validated['lines'])
                    ->pluck('catalog_product_id')
                    ->unique()
            )
            ->get(['id', 'base_unit_code'])
            ->keyBy('id');

        try {
            $correction = DB::transaction(function () use (
                $validated,
                $products,
                $request,
                $inventoryMovement,
                $creator,
                $corrector
            ) {
                $replacement = $creator->create(
                    $this->draftData(
                        $validated,
                        $products,
                        'inventory_correction_ui',
                        [
                            'created_from' =>
                                'inventory_correction_ui',
                            'corrects_public_id' =>
                                $inventoryMovement->public_id,
                        ],
                        $inventoryMovement->public_id
                    ),
                    $request->user()
                );

                return $corrector->correct(
                    $inventoryMovement,
                    $replacement,
                    $request->user(),
                    $validated['reason'],
                    $validated['idempotency_key']
                );
            }, 3);
        } catch (DomainException $exception) {
            return back()
                ->withInput()
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('inventory-movements.index', [
                'search' => $correction->original->public_id,
            ])
            ->with(
                'success',
                'Corrección aplicada: reverso #'.Str::upper(
                    Str::substr(
                        (string) $correction->reversal->public_id,
                        0,
                        8
                    )
                ).' y reemplazo #'.Str::upper(Str::substr(
                    (string) $correction->replacement->public_id,
                    0,
                    8
                )).' confirmados atómicamente.'
            );
    }

    /**
     * @param array<string, mixed> $validated
     * @param Collection<int, CatalogProduct> $products
     * @param array<string, mixed> $metadata
     */
    private function draftData(
        array $validated,
        Collection $products,
        string $sourceType,
        array $metadata,
        ?string $sourceId = null
    ): InventoryMovementDraftData {
        return new InventoryMovementDraftData(
            type: InventoryMovementType::from($validated['type']),
            effectiveAt: CarbonImmutable::parse(
                $validated['effective_at'],
                config('app.timezone')
            ),
            reason: $validated['reason'],
            idempotencyKey: $validated['idempotency_key'],
            lines: collect($validated['lines'])
                ->map(function (array $line) use (
                    $products
                ): InventoryMovementLineData {
                    $product = $products->get(
                        $line['catalog_product_id']
                    );

                    if (! $product) {
                        throw new DomainException(
                            'El producto dejó de estar disponible.'
                        );
                    }

                    return new InventoryMovementLineData(
                        catalogProductId: (int) $product->id,
                        condition: InventoryCondition::from(
                            $line['condition']
                        ),
                        enteredQuantity: $line['entered_quantity'],
                        enteredUnitCode: $product->base_unit_code,
                        sourceLocationId:
                            $line['source_location_id'] ?? null,
                        destinationLocationId:
                            $line['destination_location_id'] ?? null,
                        notes: $line['notes'] ?? null
                    );
                })->all(),
            sourceType: $sourceType,
            sourceId: $sourceId,
            sourceReference: $validated['source_reference'] ?? null,
            metadata: $metadata
        );
    }

    private function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $identity = CatalogProduct::normalizeIdentity($search);
        $publicId = preg_replace('/[^a-zA-Z0-9-]/', '', $search) ?? '';

        $query->where(function (Builder $searchQuery) use (
            $search,
            $identity,
            $publicId
        ): void {
            $searchQuery
                ->where('reason', 'like', '%'.$search.'%')
                ->orWhere('source_reference', 'like', '%'.$search.'%');

            if ($publicId !== '') {
                $searchQuery->orWhere(
                    'public_id',
                    'like',
                    '%'.$publicId.'%'
                );
            }

            if ($identity !== '') {
                $searchQuery->orWhereHas(
                    'lines.product',
                    fn (Builder $productQuery): Builder => $productQuery
                        ->where(
                            'normalized_sku',
                            'like',
                            '%'.$identity.'%'
                        )->orWhere(
                            'normalized_name',
                            'like',
                            '%'.$identity.'%'
                        )
                );
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function lineForDisplay(InventoryMovementLine $line): array
    {
        $scale = (int) ($line->product?->quantity_scale ?? 0);
        $unitCode = (string) $line->base_unit_code;

        return [
            'productName' => $line->product?->name ?? 'Producto ausente',
            'productSku' => $line->product?->sku ?? 'Sin SKU',
            'condition' => $line->condition->label(),
            'quantity' => $this->quantityForDisplay(
                (string) $line->base_quantity,
                $scale
            ),
            'unit' => InventoryBaseUnit::tryFrom($unitCode)?->label()
                ?? Str::upper($unitCode),
            'source' => $line->sourceLocation?->name ?? '—',
            'destination' => $line->destinationLocation?->name ?? '—',
            'notes' => $line->notes,
        ];
    }

    private function statusClass(InventoryMovementStatus $status): string
    {
        return match ($status) {
            InventoryMovementStatus::Draft =>
                'border-amber-500/30 bg-amber-500/10 text-amber-200',
            InventoryMovementStatus::Confirmed =>
                'border-emerald-500/30 bg-emerald-500/10 text-emerald-200',
            InventoryMovementStatus::Cancelled =>
                'border-slate-500/30 bg-slate-500/10 text-slate-300',
        };
    }

    private function quantityForDisplay(string $quantity, int $scale): string
    {
        $scale = max(0, min(InventoryQuantity::SCALE, $scale));
        [$integer, $fraction] = array_pad(
            explode('.', ltrim($quantity, '+'), 2),
            2,
            ''
        );
        $integer = preg_replace(
            '/\B(?=(\d{3})+(?!\d))/',
            '.',
            ltrim($integer, '0') ?: '0'
        ) ?? $integer;

        if ($scale === 0) {
            return $integer;
        }

        return $integer.','.substr(
            str_pad($fraction, $scale, '0'),
            0,
            $scale
        );
    }
}
