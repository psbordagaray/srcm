<?php

namespace App\Http\Controllers;

use App\Domain\Service\ServiceAssetIdentifierData;
use App\Domain\Service\ServiceOrderIntakeData;
use App\Domain\Service\ServiceOrderIntakeManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\ServiceAssetType;
use App\Enums\ServiceIdentifierType;
use App\Enums\ServiceOrderStatus;
use App\Http\Requests\StoreServiceOrderRequest;
use App\Models\BusinessParty;
use App\Models\InventoryLocation;
use App\Models\ServiceOrder;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ServiceOrderController extends Controller
{
    public function index(
        Request $request,
        CurrentOrganization $currentOrganization
    ): View {
        $organizationId = $currentOrganization->id($request->user());
        $search = Str::of((string) $request->query('search'))
            ->squish()
            ->toString();
        $status = (string) $request->query('status', '');
        $assetType = (string) $request->query('asset_type', '');
        $normalizedSearch = ServiceIdentifierType::Other
            ->normalize($search);

        if (! ServiceOrderStatus::tryFrom($status)) {
            $status = '';
        }

        if (! ServiceAssetType::tryFrom($assetType)) {
            $assetType = '';
        }

        $orders = ServiceOrder::query()
            ->forOrganization($organizationId)
            ->with([
                'asset.identifiers',
                'customer',
                'owner',
                'intake',
                'intakeLocation',
            ])
            ->when($status !== '', fn (Builder $query): Builder => $query->where('status', $status)
            )
            ->when($assetType !== '', fn (Builder $query): Builder => $query->whereHas('asset', fn (Builder $asset): Builder => $asset->where('asset_type', $assetType)
            )
            )
            ->when($search !== '', function (Builder $query) use (
                $search,
                $normalizedSearch
            ): void {
                $query->where(function (Builder $match) use (
                    $search,
                    $normalizedSearch
                ): void {
                    if (ctype_digit($search)) {
                        $match->orWhere(
                            'order_number',
                            (int) $search
                        );
                    }

                    $match
                        ->orWhere('public_id', 'like', "%{$search}%")
                        ->orWhereHas(
                            'customer',
                            fn (Builder $party): Builder => $party
                                ->where('name', 'like', "%{$search}%")
                                ->orWhere('phone', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%")
                        )
                        ->orWhereHas(
                            'owner',
                            fn (Builder $party): Builder => $party
                                ->where('name', 'like', "%{$search}%")
                        )
                        ->orWhereHas(
                            'intake',
                            fn (Builder $intake): Builder => $intake
                                ->where(
                                    'customer_name_snapshot',
                                    'like',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'owner_name_snapshot',
                                    'like',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'customer_reported_issue',
                                    'like',
                                    "%{$search}%"
                                )
                        )
                        ->orWhereHas(
                            'asset',
                            fn (Builder $asset): Builder => $asset
                                ->where('brand_name', 'like', "%{$search}%")
                                ->orWhere('model_name', 'like', "%{$search}%")
                        );

                    if ($normalizedSearch !== '') {
                        $match->orWhereHas(
                            'asset.identifiers',
                            fn (Builder $identifier): Builder => $identifier
                                ->where(
                                    'normalized_value',
                                    'like',
                                    "%{$normalizedSearch}%"
                                )
                        );
                    }
                });
            })
            ->latest('received_at')
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        $summary = [
            'open' => ServiceOrder::query()
                ->forOrganization($organizationId)
                ->whereNotIn('status', [
                    ServiceOrderStatus::Delivered->value,
                    ServiceOrderStatus::Cancelled->value,
                ])->count(),
            'awaiting_approval' => ServiceOrder::query()
                ->forOrganization($organizationId)
                ->where(
                    'status',
                    ServiceOrderStatus::AwaitingApproval->value
                )->count(),
            'external' => ServiceOrder::query()
                ->forOrganization($organizationId)
                ->where(
                    'status',
                    ServiceOrderStatus::WithExternalProvider->value
                )->count(),
            'ready' => ServiceOrder::query()
                ->forOrganization($organizationId)
                ->where(
                    'status',
                    ServiceOrderStatus::ReadyForDelivery->value
                )->count(),
        ];

        return view('service-orders.index', [
            'orders' => $orders,
            'summary' => $summary,
            'statuses' => ServiceOrderStatus::cases(),
            'assetTypes' => ServiceAssetType::cases(),
            'statusClasses' => $this->statusClasses(),
            'search' => $search,
            'status' => $status,
            'assetType' => $assetType,
        ]);
    }

    public function create(
        Request $request,
        CurrentOrganization $currentOrganization
    ): View {
        $organizationId = $currentOrganization->id($request->user());

        return view('service-orders.create', [
            'assetTypes' => ServiceAssetType::cases(),
            'identifierTypes' => ServiceIdentifierType::cases(),
            'locations' => InventoryLocation::query()
                ->forOrganization($organizationId)
                ->active()
                ->orderBy('name')
                ->get(['id', 'name']),
            'parties' => BusinessParty::query()
                ->forOrganization($organizationId)
                ->orderBy('name')
                ->get(['id', 'name', 'phone', 'email']),
            'idempotencyKey' => 'service-ui:'.Str::uuid(),
        ]);
    }

    public function store(
        StoreServiceOrderRequest $request,
        ServiceOrderIntakeManager $manager
    ): RedirectResponse {
        $validated = $request->validated();

        try {
            $order = $manager->create(
                new ServiceOrderIntakeData(
                    assetType: ServiceAssetType::from(
                        $validated['asset_type']
                    ),
                    brandName: $validated['brand_name'],
                    modelName: $validated['model_name'],
                    identifiers: collect(
                        $validated['identifiers'] ?? []
                    )->map(fn (array $identifier): ServiceAssetIdentifierData => new ServiceAssetIdentifierData(
                        ServiceIdentifierType::from(
                            $identifier['type']
                        ),
                        $identifier['value']
                    )
                    )->values()->all(),
                    intakeLocationId: $validated['intake_location_id'],
                    customerReportedIssue: $validated['customer_reported_issue'],
                    idempotencyKey: $validated['idempotency_key'],
                    customerBusinessPartyId: $validated['customer_business_party_id'] ?? null,
                    customerName: $validated['customer_name'] ?? null,
                    ownerBusinessPartyId: $validated['owner_business_party_id'] ?? null,
                    ownerName: $validated['owner_name'] ?? null,
                    color: $validated['color'] ?? null,
                    intakeObservations: $validated['intake_observations'] ?? null,
                    receivedAccessories: $validated['received_accessories'] ?? null,
                    contactAvailable: (bool)
                        $validated['contact_available'],
                    contactReference: $validated['contact_reference'] ?? null,
                    promisedAt: filled($validated['promised_at'] ?? null)
                        ? CarbonImmutable::parse(
                            $validated['promised_at'],
                            config('app.timezone')
                        )
                        : null,
                    metadata: ['source' => 'service-orders-ui']
                ),
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()
                ->withInput()
                ->withErrors(['service_order' => $exception->getMessage()]);
        }

        return redirect()
            ->route('service-orders.show', $order)
            ->with(
                'success',
                "Orden #{$order->order_number} recibida y custodia registrada."
            );
    }

    public function show(
        Request $request,
        ServiceOrder $serviceOrder,
        CurrentOrganization $currentOrganization
    ): View {
        $organizationId = $currentOrganization->id($request->user());

        abort_unless(
            (int) $serviceOrder->organization_id === $organizationId,
            404
        );

        $serviceOrder->load([
            'asset.identifiers',
            'customer',
            'owner',
            'intake.recordedBy',
            'intakeLocation',
            'createdBy',
            'statusHistory.changedBy',
            'custodyEvents.location',
            'custodyEvents.recordedBy',
            'diagnostics.findings',
            'diagnostics.diagnosedBy',
            'quotes.options.lines',
            'quotes.diagnostic',
            'quotes.decision.selectedOption',
            'quotes.decision.recordedBy',
            'quotes.issuedBy',
            'workItems.provider',
            'workItems.assignedUser',
            'workItems.report.recordedBy',
            'workItems.statusHistory.changedBy',
            'workItems.custodyLinks.custodyEvent.recordedBy',
            'workItems.warrantyResolution.claim',
            'workItems.approvedOption.lines.partRequirement',
            'partRequirements.product',
            'partRequirements.workItem',
            'partRequirements.quoteLine',
            'partRequirements.warrantyResolution.claim',
            'partRequirements.createdBy',
            'partRequirements.purchaseLines.purchase.supplier.party',
            'partRequirements.purchaseLines.consumptions',
            'partRequirements.consumptions.consumedBy',
            'partRequirements.consumptions.inventoryMovementLine.movement',
            'partPurchases.supplier.party',
            'partPurchases.purchasedBy',
            'partPurchases.lines.requirement.product',
            'partPurchases.lines.consumptions',
            'qualityInspections.inspectedBy',
            'qualityInspections.delivery',
            'delivery.qualityInspection',
            'delivery.custodyEvent',
            'delivery.recipient',
            'delivery.deliveredBy',
            'delivery.warranties.workReport.workItem',
            'delivery.warranties.claims.correctiveOrder',
            'delivery.warranties.claims.receivedBy',
            'delivery.warranties.claims.statusHistory.changedBy',
            'delivery.warranties.claims.resolution.resolvedBy',
            'delivery.warranties.claims.returnRecord.returnedBy',
            'warrantyClaimsAsOriginal.warrantyGrant',
            'warrantyClaimsAsOriginal.correctiveOrder',
            'warrantyClaimsAsOriginal.receivedBy',
            'warrantyClaimsAsOriginal.statusHistory.changedBy',
            'warrantyClaimsAsOriginal.resolution.resolvedBy',
            'warrantyClaimsAsOriginal.returnRecord.returnedBy',
            'warrantyClaimAsCorrective.warrantyGrant',
            'warrantyClaimAsCorrective.originalOrder',
            'warrantyClaimAsCorrective.receivedBy',
            'warrantyClaimAsCorrective.intakeLocation',
            'warrantyClaimAsCorrective.statusHistory.changedBy',
            'warrantyClaimAsCorrective.resolution.resolvedBy',
            'warrantyClaimAsCorrective.returnRecord.recipient',
            'warrantyClaimAsCorrective.returnRecord.returnedBy',
            'warrantyClaimAsCorrective.returnRecord.custodyEvent',
            'evidences.uploadedBy',
            'commerceSale',
            'cancellationRequest.requester',
            'cancellationRequest.requestedBy',
            'cancellationRequest.resolution.resolvedBy',
            'cancellationRequest.resolution.returnRecord.recipient',
            'cancellationRequest.resolution.returnRecord.returnedBy',
            'cancellationRequest.resolution.returnRecord.custodyEvent',
        ]);

        return view('service-orders.show', [
            'order' => $serviceOrder,
            'statusClass' => $this->statusClasses()[
                $serviceOrder->status->value
            ],
        ]);
    }

    /** @return array<string, string> */
    private function statusClasses(): array
    {
        return [
            ServiceOrderStatus::Received->value => 'border-sky-400/30 bg-sky-400/10 text-sky-300',
            ServiceOrderStatus::Diagnosing->value => 'border-violet-400/30 bg-violet-400/10 text-violet-300',
            ServiceOrderStatus::AwaitingApproval->value => 'border-amber-400/30 bg-amber-400/10 text-amber-300',
            ServiceOrderStatus::AwaitingParts->value => 'border-orange-400/30 bg-orange-400/10 text-orange-300',
            ServiceOrderStatus::InProgress->value => 'border-cyan-400/30 bg-cyan-400/10 text-cyan-300',
            ServiceOrderStatus::WithExternalProvider->value => 'border-fuchsia-400/30 bg-fuchsia-400/10 text-fuchsia-300',
            ServiceOrderStatus::QualityControl->value => 'border-indigo-400/30 bg-indigo-400/10 text-indigo-300',
            ServiceOrderStatus::ReadyForDelivery->value => 'border-emerald-400/30 bg-emerald-400/10 text-emerald-300',
            ServiceOrderStatus::Delivered->value => 'border-slate-500/30 bg-slate-500/10 text-slate-300',
            ServiceOrderStatus::CancellationPending->value => 'border-rose-400/30 bg-rose-400/10 text-rose-300',
            ServiceOrderStatus::ReadyForReturn->value => 'border-orange-400/30 bg-orange-400/10 text-orange-300',
            ServiceOrderStatus::Cancelled->value => 'border-red-400/30 bg-red-400/10 text-red-300',
        ];
    }
}
