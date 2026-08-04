<?php

namespace App\Http\Controllers;

use App\Domain\Service\ServiceWarrantyClaimData;
use App\Domain\Service\ServiceWarrantyClaimManager;
use App\Domain\Service\ServiceWarrantyClaimResolutionData;
use App\Domain\Service\ServiceWarrantyClaimReturnData;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\ServiceOrderStatus;
use App\Enums\ServiceWarrantyClaimOutcome;
use App\Enums\ServiceWarrantyClaimStatus;
use App\Http\Requests\ResolveServiceWarrantyClaimRequest;
use App\Http\Requests\ReturnServiceWarrantyClaimRequest;
use App\Http\Requests\StoreServiceWarrantyClaimRequest;
use App\Models\BusinessParty;
use App\Models\InventoryLocation;
use App\Models\ServiceOrder;
use App\Models\ServiceWarrantyClaim;
use App\Models\ServiceWarrantyGrant;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ServiceWarrantyClaimController extends Controller
{
    public function createRegistration(
        Request $request,
        ServiceOrder $serviceOrder,
        ServiceWarrantyGrant $serviceWarrantyGrant,
        CurrentOrganization $currentOrganization
    ): View {
        $organizationId = $this->guardWarranty(
            $request,
            $serviceOrder,
            $serviceWarrantyGrant,
            $currentOrganization
        );
        abort_unless(
            $serviceOrder->status === ServiceOrderStatus::Delivered,
            409
        );

        $serviceWarrantyGrant->loadMissing(['workReport', 'claims']);
        abort_if(
            $serviceWarrantyGrant->claims->contains(
                fn (ServiceWarrantyClaim $claim): bool => $claim->status->isOpen()
            ),
            409
        );
        $serviceOrder->load([
            'asset.identifiers',
            'customer',
            'intake',
            'delivery',
        ]);

        return view('service-orders.warranty.register', [
            'order' => $serviceOrder,
            'warranty' => $serviceWarrantyGrant,
            'locations' => InventoryLocation::query()
                ->forOrganization($organizationId)
                ->active()
                ->orderBy('name')
                ->get(['id', 'name']),
            'parties' => BusinessParty::query()
                ->forOrganization($organizationId)
                ->orderBy('name')
                ->get(['id', 'name', 'phone', 'email']),
            'idempotencyKey' => 'service-ui:warranty-claim:'.Str::uuid(),
        ]);
    }

    public function storeRegistration(
        StoreServiceWarrantyClaimRequest $request,
        ServiceOrder $serviceOrder,
        ServiceWarrantyGrant $serviceWarrantyGrant,
        CurrentOrganization $currentOrganization,
        ServiceWarrantyClaimManager $manager
    ): RedirectResponse {
        $this->guardWarranty(
            $request,
            $serviceOrder,
            $serviceWarrantyGrant,
            $currentOrganization
        );
        $validated = $request->validated();

        try {
            $claim = $manager->register(
                new ServiceWarrantyClaimData(
                    serviceWarrantyGrantId: $serviceWarrantyGrant->id,
                    intakeLocationId: $validated['intake_location_id'],
                    claimantName: $validated['claimant_name'],
                    reportedIssue: $validated['reported_issue'],
                    reentryConditionNotes: $validated['reentry_condition_notes'],
                    accessoriesSnapshot: $validated['accessories_snapshot'],
                    channel: $validated['channel'],
                    claimedAt: $this->parseLocalTimestamp(
                        $validated['claimed_at']
                    ),
                    idempotencyKey: $validated['idempotency_key'],
                    claimantBusinessPartyId: $validated['claimant_business_party_id'] ?? null,
                    customerReference: $validated['customer_reference'] ?? null
                ),
                $request->user()
            );
        } catch (DomainException $exception) {
            return $this->domainFailure($exception);
        }

        return redirect()
            ->route('service-orders.show', $claim->correctiveOrder)
            ->with(
                'success',
                'Reclamo registrado. Se creó la orden correctiva y la custodia de reingreso.'
            );
    }

    public function createResolution(
        Request $request,
        ServiceOrder $serviceOrder,
        ServiceWarrantyClaim $serviceWarrantyClaim,
        CurrentOrganization $currentOrganization
    ): View {
        $this->guardClaim(
            $request,
            $serviceOrder,
            $serviceWarrantyClaim,
            $currentOrganization
        );
        abort_unless(
            $serviceWarrantyClaim->status
                === ServiceWarrantyClaimStatus::PendingReview,
            409
        );

        $serviceWarrantyClaim->load([
            'warrantyGrant.workReport',
            'originalOrder.asset',
            'correctiveOrder.asset',
            'claimant',
            'receivedBy',
            'intakeLocation',
            'resolution',
        ]);
        abort_if($serviceWarrantyClaim->resolution !== null, 409);

        return view('service-orders.warranty.resolve', [
            'order' => $serviceOrder,
            'claim' => $serviceWarrantyClaim,
            'outcomes' => ServiceWarrantyClaimOutcome::cases(),
            'idempotencyKey' => 'service-ui:warranty-resolution:'.Str::uuid(),
        ]);
    }

    public function storeResolution(
        ResolveServiceWarrantyClaimRequest $request,
        ServiceOrder $serviceOrder,
        ServiceWarrantyClaim $serviceWarrantyClaim,
        CurrentOrganization $currentOrganization,
        ServiceWarrantyClaimManager $manager
    ): RedirectResponse {
        $this->guardClaim(
            $request,
            $serviceOrder,
            $serviceWarrantyClaim,
            $currentOrganization
        );
        $validated = $request->validated();

        try {
            $manager->resolve(
                new ServiceWarrantyClaimResolutionData(
                    serviceWarrantyClaimId: $serviceWarrantyClaim->id,
                    outcome: ServiceWarrantyClaimOutcome::from(
                        $validated['outcome']
                    ),
                    technicalBasis: $validated['technical_basis'],
                    idempotencyKey: $validated['idempotency_key'],
                    coveredScope: $validated['covered_scope'] ?? null,
                    excludedScope: $validated['excluded_scope'] ?? null,
                    exceptionReason: $validated['exception_reason'] ?? null,
                    notes: $validated['notes'] ?? null
                ),
                $request->user()
            );
        } catch (DomainException $exception) {
            return $this->domainFailure($exception);
        }

        return redirect()
            ->route('service-orders.show', $serviceWarrantyClaim->correctiveOrder)
            ->with('success', 'Reclamo de garantía resuelto y trazado.');
    }

    public function createReturn(
        Request $request,
        ServiceOrder $serviceOrder,
        ServiceWarrantyClaim $serviceWarrantyClaim,
        CurrentOrganization $currentOrganization
    ): View {
        $organizationId = $this->guardClaim(
            $request,
            $serviceOrder,
            $serviceWarrantyClaim,
            $currentOrganization
        );
        $serviceWarrantyClaim->load([
            'correctiveOrder.asset',
            'correctiveOrder.intake',
            'claimant',
            'resolution',
            'returnRecord',
        ]);
        abort_unless(
            $serviceWarrantyClaim->status
                === ServiceWarrantyClaimStatus::ReadyForReturn
                && $serviceWarrantyClaim->resolution?->outcome
                    === ServiceWarrantyClaimOutcome::Rejected
                && $serviceWarrantyClaim->correctiveOrder?->status
                    === ServiceOrderStatus::ReadyForReturn,
            409
        );
        abort_if($serviceWarrantyClaim->returnRecord !== null, 409);

        return view('service-orders.warranty.return', [
            'order' => $serviceOrder,
            'claim' => $serviceWarrantyClaim,
            'parties' => BusinessParty::query()
                ->forOrganization($organizationId)
                ->orderBy('name')
                ->get(['id', 'name', 'phone', 'email']),
            'idempotencyKey' => 'service-ui:warranty-return:'.Str::uuid(),
        ]);
    }

    public function storeReturn(
        ReturnServiceWarrantyClaimRequest $request,
        ServiceOrder $serviceOrder,
        ServiceWarrantyClaim $serviceWarrantyClaim,
        CurrentOrganization $currentOrganization,
        ServiceWarrantyClaimManager $manager
    ): RedirectResponse {
        $this->guardClaim(
            $request,
            $serviceOrder,
            $serviceWarrantyClaim,
            $currentOrganization
        );
        $validated = $request->validated();

        try {
            $manager->returnAsset(
                new ServiceWarrantyClaimReturnData(
                    serviceWarrantyClaimId: $serviceWarrantyClaim->id,
                    recipientName: $validated['recipient_name'],
                    conditionNotes: $validated['condition_notes'],
                    accessoriesSnapshot: $validated['accessories_snapshot'],
                    idempotencyKey: $validated['idempotency_key'],
                    recipientBusinessPartyId: $validated['recipient_business_party_id'] ?? null,
                    recipientDocument: $validated['recipient_document'] ?? null,
                    notes: $validated['notes'] ?? null,
                    returnedAt: filled($validated['returned_at'] ?? null)
                        ? $this->parseLocalTimestamp(
                            $validated['returned_at']
                        )
                        : null
                ),
                $request->user()
            );
        } catch (DomainException $exception) {
            return $this->domainFailure($exception);
        }

        return redirect()
            ->route('service-orders.show', $serviceWarrantyClaim->correctiveOrder)
            ->with(
                'success',
                'Equipo devuelto y reclamo de garantía cerrado definitivamente.'
            );
    }

    private function guardWarranty(
        Request $request,
        ServiceOrder $serviceOrder,
        ServiceWarrantyGrant $serviceWarrantyGrant,
        CurrentOrganization $currentOrganization
    ): int {
        $organizationId = $currentOrganization->id($request->user());
        $serviceWarrantyGrant->loadMissing('delivery');

        abort_unless(
            (int) $serviceOrder->organization_id === $organizationId
                && (int) $serviceWarrantyGrant->organization_id
                    === $organizationId
                && (int) $serviceWarrantyGrant->delivery?->service_order_id
                    === (int) $serviceOrder->id,
            404
        );

        return $organizationId;
    }

    private function guardClaim(
        Request $request,
        ServiceOrder $serviceOrder,
        ServiceWarrantyClaim $serviceWarrantyClaim,
        CurrentOrganization $currentOrganization
    ): int {
        $organizationId = $currentOrganization->id($request->user());

        abort_unless(
            (int) $serviceOrder->organization_id === $organizationId
                && (int) $serviceWarrantyClaim->organization_id
                    === $organizationId
                && in_array(
                    (int) $serviceOrder->id,
                    [
                        (int) $serviceWarrantyClaim->original_service_order_id,
                        (int) $serviceWarrantyClaim->corrective_service_order_id,
                    ],
                    true
                ),
            404
        );

        return $organizationId;
    }

    private function parseLocalTimestamp(
        string $value
    ): CarbonImmutable {
        $timestamp = CarbonImmutable::parse(
            $value,
            config('app.timezone')
        );

        if (
            preg_match(
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/',
                $value
            ) === 1
        ) {
            $timestamp = $timestamp->endOfMinute();
            $now = CarbonImmutable::now(config('app.timezone'));

            if ($timestamp->isAfter($now)) {
                return $now;
            }
        }

        return $timestamp;
    }

    private function domainFailure(
        DomainException $exception
    ): RedirectResponse {
        return back()->withInput()->withErrors([
            'warranty_claim' => $exception->getMessage(),
        ]);
    }
}
