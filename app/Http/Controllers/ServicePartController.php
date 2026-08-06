<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\InventoryQuantity;
use App\Domain\Service\ServicePartConsumptionData;
use App\Domain\Service\ServicePartManager;
use App\Domain\Service\ServicePartPurchaseData;
use App\Domain\Service\ServicePartPurchaseLineData;
use App\Domain\Service\ServicePartRequirementData;
use App\Domain\Service\ServiceWarrantyPartRequirementData;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\InventoryCondition;
use App\Enums\ServiceOrderStatus;
use App\Enums\ServicePartSource;
use App\Enums\ServiceQuoteLineType;
use App\Enums\ServiceWorkStatus;
use App\Http\Requests\StoreServicePartConsumptionRequest;
use App\Http\Requests\StoreServicePartPurchaseRequest;
use App\Http\Requests\StoreServicePartRequirementRequest;
use App\Models\CatalogProduct;
use App\Models\InventoryLocation;
use App\Models\ServiceOrder;
use App\Models\ServicePartRequirement;
use App\Models\ServiceQuoteLine;
use App\Models\ServiceWorkItem;
use App\Models\Supplier;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class ServicePartController extends Controller
{
    public function createRequirement(
        Request $request,
        ServiceOrder $serviceOrder,
        ServiceWorkItem $serviceWorkItem,
        CurrentOrganization $currentOrganization
    ): View {
        $organizationId = $this->guardWork(
            $request,
            $serviceOrder,
            $serviceWorkItem,
            $currentOrganization
        );

        abort_unless(
            in_array($serviceOrder->status, [
                ServiceOrderStatus::InProgress,
                ServiceOrderStatus::AwaitingParts,
            ], true)
            && in_array($serviceWorkItem->status, [
                ServiceWorkStatus::Planned,
                ServiceWorkStatus::InProgress,
            ], true),
            409
        );

        $serviceOrder->load(['asset', 'intake']);
        $serviceWorkItem->load([
            'approvedOption.lines.partRequirement',
            'warrantyResolution.claim',
            'partRequirements.product',
        ]);

        $warrantyMode =
            $serviceWorkItem->service_warranty_claim_resolution_id !== null;

        $quoteLines = $warrantyMode
            ? collect()
            : ($serviceWorkItem->approvedOption?->lines ?? collect())
                ->filter(
                    fn ($line): bool => $line->line_type === ServiceQuoteLineType::Part
                        && $line->partRequirement === null
                )
                ->values();

        abort_if(! $warrantyMode && $quoteLines->isEmpty(), 409);

        return view('service-orders.parts.requirement', [
            'order' => $serviceOrder,
            'work' => $serviceWorkItem,
            'warrantyMode' => $warrantyMode,
            'quoteLines' => $quoteLines,
            'products' => CatalogProduct::query()
                ->where('active', true)
                ->orderBy('name')
                ->get([
                    'id',
                    'sku',
                    'name',
                    'base_unit_code',
                    'quantity_scale',
                ]),
            'conditions' => InventoryCondition::cases(),
            'sources' => ServicePartSource::cases(),
            'idempotencyKey' => 'service-ui:part-requirement:'.Str::uuid(),
            'organizationId' => $organizationId,
        ]);
    }

    public function storeRequirement(
        StoreServicePartRequirementRequest $request,
        ServiceOrder $serviceOrder,
        ServiceWorkItem $serviceWorkItem,
        CurrentOrganization $currentOrganization,
        ServicePartManager $manager
    ): RedirectResponse {
        $organizationId = $this->guardWork(
            $request,
            $serviceOrder,
            $serviceWorkItem,
            $currentOrganization
        );
        $validated = $request->validated();

        try {
            if (
                $serviceWorkItem
                    ->service_warranty_claim_resolution_id !== null
            ) {
                $requirement = $manager->planWarranty(
                    new ServiceWarrantyPartRequirementData(
                        serviceWorkItemId: $serviceWorkItem->id,
                        serviceWarrantyClaimResolutionId: (int) $serviceWorkItem
                            ->service_warranty_claim_resolution_id,
                        catalogProductId: $validated['catalog_product_id'],
                        condition: InventoryCondition::from(
                            $validated['condition']
                        ),
                        source: ServicePartSource::from(
                            $validated['source']
                        ),
                        requiredQuantity: $validated['required_quantity'],
                        idempotencyKey: $validated['idempotency_key']
                    ),
                    $request->user()
                );
            } else {
                $quoteLine = ServiceQuoteLine::query()
                    ->forOrganization($organizationId)
                    ->whereKey($validated['service_quote_line_id'])
                    ->where(
                        'service_quote_option_id',
                        $serviceWorkItem->service_quote_option_id
                    )
                    ->where(
                        'line_type',
                        ServiceQuoteLineType::Part->value
                    )
                    ->first();

                if (! $quoteLine) {
                    throw new DomainException(
                        'La línea seleccionada no pertenece al alcance aprobado.'
                    );
                }

                $requirement = $manager->plan(
                    new ServicePartRequirementData(
                        serviceWorkItemId: $serviceWorkItem->id,
                        serviceQuoteLineId: $quoteLine->id,
                        catalogProductId: $validated['catalog_product_id'],
                        condition: InventoryCondition::from(
                            $validated['condition']
                        ),
                        source: ServicePartSource::from(
                            $validated['source']
                        ),
                        requiredQuantity: (string) $quoteLine->quantity,
                        idempotencyKey: $validated['idempotency_key']
                    ),
                    $request->user()
                );
            }
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors([
                'parts' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('service-orders.show', $serviceOrder)
            ->with(
                'success',
                'Repuesto planificado: '
                    .$requirement->product->name
                    .' · '
                    .$requirement->source->label()
                    .'.'
            );
    }

    public function createPurchase(
        Request $request,
        ServiceOrder $serviceOrder,
        CurrentOrganization $currentOrganization
    ): View {
        $organizationId = $this->guardOrder(
            $request,
            $serviceOrder,
            $currentOrganization
        );

        abort_unless(
            in_array($serviceOrder->status, [
                ServiceOrderStatus::AwaitingParts,
                ServiceOrderStatus::InProgress,
            ], true),
            409
        );

        $requirements = $this->directPurchaseRequirements(
            $organizationId,
            $serviceOrder
        );

        abort_if($requirements->isEmpty(), 409);

        return view('service-orders.parts.purchase', [
            'order' => $serviceOrder->load(['asset', 'intake']),
            'requirements' => $requirements,
            'suppliers' => Supplier::query()
                ->forOrganization($organizationId)
                ->with('party')
                ->where('active', true)
                ->get()
                ->sortBy(fn (Supplier $supplier): string => $supplier->party->name
                )
                ->values(),
            'idempotencyKey' => 'service-ui:part-purchase:'.Str::uuid(),
        ]);
    }

    public function storePurchase(
        StoreServicePartPurchaseRequest $request,
        ServiceOrder $serviceOrder,
        CurrentOrganization $currentOrganization,
        ServicePartManager $manager
    ): RedirectResponse {
        $this->guardOrder(
            $request,
            $serviceOrder,
            $currentOrganization
        );
        $validated = $request->validated();

        try {
            $purchase = $manager->recordPurchase(
                new ServicePartPurchaseData(
                    serviceOrderId: $serviceOrder->id,
                    supplierId: $validated['supplier_id'],
                    currencyCode: $validated['currency_code'],
                    purchasedAt: CarbonImmutable::parse(
                        $validated['purchased_at'],
                        config('app.timezone')
                    ),
                    lines: collect($validated['lines'])
                        ->map(
                            fn (array $line): ServicePartPurchaseLineData => new ServicePartPurchaseLineData(
                                servicePartRequirementId: $line[
                                        'service_part_requirement_id'
                                    ],
                                quantity: $line['quantity'],
                                unitCostMinor: $this->minorAmount(
                                    $line['unit_cost']
                                )
                            )
                        )
                        ->values()
                        ->all(),
                    idempotencyKey: $validated['idempotency_key'],
                    logisticsCostMinor: $this->minorAmount(
                        $validated['logistics_cost']
                    ),
                    documentReference: $validated['document_reference'] ?? null,
                    notes: $validated['notes'] ?? null
                ),
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors([
                'parts' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('service-orders.show', $serviceOrder)
            ->with(
                'success',
                'Compra de repuestos registrada por $ '
                    .number_format(
                        $purchase->grand_total_minor / 100,
                        2,
                        ',',
                        '.'
                    )
                    .'.'
            );
    }

    public function createConsumption(
        Request $request,
        ServiceOrder $serviceOrder,
        ServicePartRequirement $servicePartRequirement,
        CurrentOrganization $currentOrganization
    ): View {
        $organizationId = $this->guardRequirement(
            $request,
            $serviceOrder,
            $servicePartRequirement,
            $currentOrganization
        );

        $servicePartRequirement->load([
            'product',
            'workItem',
            'purchaseLines.purchase.supplier.party',
            'purchaseLines.consumptions',
            'consumptions',
        ]);

        abort_unless(
            $serviceOrder->status === ServiceOrderStatus::InProgress
            && $servicePartRequirement->workItem->status
                === ServiceWorkStatus::InProgress,
            409
        );

        $consumed = $this->sumQuantities(
            $servicePartRequirement->consumptions,
            'quantity'
        );
        $remaining = InventoryQuantity::nonNegative(
            InventoryQuantity::subtract(
                $servicePartRequirement->required_quantity,
                $consumed
            )
        );

        abort_unless(InventoryQuantity::isPositive($remaining), 409);

        $purchaseLines = $servicePartRequirement->purchaseLines
            ->map(function ($line) {
                $consumed = $this->sumQuantities(
                    $line->consumptions,
                    'quantity'
                );
                $available = InventoryQuantity::nonNegative(
                    InventoryQuantity::subtract(
                        $line->quantity,
                        $consumed
                    )
                );
                $line->setAttribute('available_quantity', $available);

                return $line;
            })
            ->filter(
                fn ($line): bool => InventoryQuantity::isPositive(
                    $line->available_quantity
                )
            )
            ->values();

        if (
            $servicePartRequirement->source
                === ServicePartSource::DirectPurchase
        ) {
            abort_if($purchaseLines->isEmpty(), 409);
        }

        return view('service-orders.parts.consume', [
            'order' => $serviceOrder->load(['asset', 'intake']),
            'requirement' => $servicePartRequirement,
            'remainingQuantity' => $remaining,
            'locations' => InventoryLocation::query()
                ->forOrganization($organizationId)
                ->active()
                ->orderBy('name')
                ->get(['id', 'name', 'type']),
            'purchaseLines' => $purchaseLines,
            'idempotencyKey' => 'service-ui:part-consumption:'.Str::uuid(),
        ]);
    }

    public function storeConsumption(
        StoreServicePartConsumptionRequest $request,
        ServiceOrder $serviceOrder,
        ServicePartRequirement $servicePartRequirement,
        CurrentOrganization $currentOrganization,
        ServicePartManager $manager
    ): RedirectResponse {
        $this->guardRequirement(
            $request,
            $serviceOrder,
            $servicePartRequirement,
            $currentOrganization
        );
        $validated = $request->validated();

        try {
            $consumption = $manager->consume(
                new ServicePartConsumptionData(
                    servicePartRequirementId: $servicePartRequirement->id,
                    quantity: $validated['quantity'],
                    idempotencyKey: $validated['idempotency_key'],
                    sourceLocationId: $validated['source_location_id'] ?? null,
                    servicePartPurchaseLineId: $validated[
                            'service_part_purchase_line_id'
                        ] ?? null
                ),
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors([
                'parts' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('service-orders.show', $serviceOrder)
            ->with(
                'success',
                'Consumo registrado: '
                    .$consumption->quantity
                    .' '
                    .$servicePartRequirement->base_unit_code
                    .'.'
            );
    }

    private function guardOrder(
        Request $request,
        ServiceOrder $serviceOrder,
        CurrentOrganization $currentOrganization
    ): int {
        $organizationId = $currentOrganization->id($request->user());

        abort_unless(
            (int) $serviceOrder->organization_id === $organizationId,
            404
        );

        return $organizationId;
    }

    private function guardWork(
        Request $request,
        ServiceOrder $serviceOrder,
        ServiceWorkItem $serviceWorkItem,
        CurrentOrganization $currentOrganization
    ): int {
        $organizationId = $this->guardOrder(
            $request,
            $serviceOrder,
            $currentOrganization
        );

        abort_unless(
            (int) $serviceWorkItem->organization_id === $organizationId
            && (int) $serviceWorkItem->service_order_id
                === (int) $serviceOrder->id,
            404
        );

        return $organizationId;
    }

    private function guardRequirement(
        Request $request,
        ServiceOrder $serviceOrder,
        ServicePartRequirement $servicePartRequirement,
        CurrentOrganization $currentOrganization
    ): int {
        $organizationId = $this->guardOrder(
            $request,
            $serviceOrder,
            $currentOrganization
        );

        abort_unless(
            (int) $servicePartRequirement->organization_id
                === $organizationId
            && (int) $servicePartRequirement->service_order_id
                === (int) $serviceOrder->id,
            404
        );

        return $organizationId;
    }

    /**
     * @return Collection<int, ServicePartRequirement>
     */
    private function directPurchaseRequirements(
        int $organizationId,
        ServiceOrder $serviceOrder
    ): Collection {
        return ServicePartRequirement::query()
            ->forOrganization($organizationId)
            ->where('service_order_id', $serviceOrder->id)
            ->where('source', ServicePartSource::DirectPurchase->value)
            ->with([
                'product',
                'workItem',
                'purchaseLines',
            ])
            ->orderBy('id')
            ->get()
            ->map(function (ServicePartRequirement $requirement) {
                $purchased = $this->sumQuantities(
                    $requirement->purchaseLines,
                    'quantity'
                );
                $remaining = InventoryQuantity::nonNegative(
                    InventoryQuantity::subtract(
                        $requirement->required_quantity,
                        $purchased
                    )
                );

                $requirement->setAttribute(
                    'purchased_quantity',
                    $purchased
                );
                $requirement->setAttribute(
                    'purchase_remaining',
                    $remaining
                );

                return $requirement;
            })
            ->filter(
                fn (ServicePartRequirement $requirement): bool => InventoryQuantity::isPositive(
                    $requirement->purchase_remaining
                )
            )
            ->values();
    }

    private function sumQuantities(
        Collection $items,
        string $attribute
    ): string {
        return $items->reduce(
            fn (string $total, $item): string => InventoryQuantity::add(
                $total,
                (string) $item->{$attribute}
            ),
            InventoryQuantity::signed('0')
        );
    }

    private function minorAmount(string $value): int
    {
        try {
            return (int) (string) BigDecimal::of($value)
                ->multipliedBy(100)
                ->toScale(0, RoundingMode::Unnecessary)
                ->toBigInteger();
        } catch (Throwable $exception) {
            throw new DomainException(
                'El importe contiene una fracción menor a un centavo.',
                previous: $exception
            );
        }
    }
}
