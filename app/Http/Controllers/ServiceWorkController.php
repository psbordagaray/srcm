<?php

namespace App\Http\Controllers;

use App\Domain\Service\ServiceWarrantyWorkItemData;
use App\Domain\Service\ServiceWorkCustodyData;
use App\Domain\Service\ServiceWorkItemData;
use App\Domain\Service\ServiceWorkManager;
use App\Domain\Service\ServiceWorkReportData;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\ServiceOrderStatus;
use App\Enums\ServiceQuoteDecisionType;
use App\Enums\ServiceWorkExecutionMode;
use App\Enums\ServiceWorkOutcome;
use App\Enums\ServiceWorkStatus;
use App\Http\Requests\StartServiceWorkRequest;
use App\Http\Requests\StoreServiceWorkCustodyRequest;
use App\Http\Requests\StoreServiceWorkReportRequest;
use App\Http\Requests\StoreServiceWorkRequest;
use App\Models\BusinessParty;
use App\Models\OrganizationMembership;
use App\Models\ServiceOrder;
use App\Models\ServiceQuoteDecision;
use App\Models\ServiceWarrantyClaimResolution;
use App\Models\ServiceWorkItem;
use App\Models\User;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ServiceWorkController extends Controller
{
    public function create(
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
            $serviceOrder->status === ServiceOrderStatus::InProgress,
            409
        );

        $serviceOrder->load([
            'asset',
            'intake',
            'warrantyClaimAsCorrective.resolution',
        ]);
        $warrantyResolution = $this->warrantyResolution($serviceOrder);
        $approvedOption = $warrantyResolution
            ? null
            : $this->approvedDecision(
                $organizationId,
                $serviceOrder
            )->selectedOption;

        return view('service-orders.work.create', [
            'order' => $serviceOrder,
            'executionModes' => ServiceWorkExecutionMode::cases(),
            'members' => $this->members($organizationId),
            'providers' => BusinessParty::query()
                ->forOrganization($organizationId)
                ->orderBy('name')
                ->get(['id', 'name', 'party_type']),
            'approvedOption' => $approvedOption,
            'warrantyResolution' => $warrantyResolution,
            'idempotencyKey' => 'service-ui:work-plan:'.Str::uuid(),
        ]);
    }

    public function store(
        StoreServiceWorkRequest $request,
        ServiceOrder $serviceOrder,
        CurrentOrganization $currentOrganization,
        ServiceWorkManager $manager
    ): RedirectResponse {
        $organizationId = $this->guardOrder(
            $request,
            $serviceOrder,
            $currentOrganization
        );
        $validated = $request->validated();
        $executionMode = ServiceWorkExecutionMode::from(
            $validated['execution_mode']
        );

        try {
            $resolution = $this->warrantyResolution($serviceOrder);

            $work = $resolution
                ? $manager->planWarranty(
                    new ServiceWarrantyWorkItemData(
                        serviceOrderId: $serviceOrder->id,
                        serviceWarrantyClaimResolutionId: $resolution->id,
                        title: $validated['title'],
                        description: $validated['description'],
                        executionMode: $executionMode,
                        idempotencyKey: $validated['idempotency_key'],
                        providerBusinessPartyId: $validated['provider_business_party_id'] ?? null,
                        assignedUserId: $validated['assigned_user_id'] ?? null
                    ),
                    $request->user()
                )
                : $manager->plan(
                    new ServiceWorkItemData(
                        serviceOrderId: $serviceOrder->id,
                        serviceQuoteOptionId: $this->approvedDecision(
                            $organizationId,
                            $serviceOrder
                        )->selectedOption->id,
                        title: $validated['title'],
                        description: $validated['description'],
                        executionMode: $executionMode,
                        idempotencyKey: $validated['idempotency_key'],
                        providerBusinessPartyId: $validated['provider_business_party_id'] ?? null,
                        assignedUserId: $validated['assigned_user_id'] ?? null
                    ),
                    $request->user()
                );
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors([
                'work' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('service-orders.show', $serviceOrder)
            ->with(
                'success',
                "Trabajo {$work->sequence} planificado: {$work->title}."
            );
    }

    public function start(
        StartServiceWorkRequest $request,
        ServiceOrder $serviceOrder,
        ServiceWorkItem $serviceWorkItem,
        CurrentOrganization $currentOrganization,
        ServiceWorkManager $manager
    ): RedirectResponse {
        $this->guardWork(
            $request,
            $serviceOrder,
            $serviceWorkItem,
            $currentOrganization
        );
        $validated = $request->validated();

        try {
            $manager->startInternal(
                $serviceWorkItem->id,
                $validated['idempotency_key'],
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()->withErrors([
                'work' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('service-orders.show', $serviceOrder)
            ->with('success', 'La ejecución interna fue iniciada.');
    }

    public function createDispatch(
        Request $request,
        ServiceOrder $serviceOrder,
        ServiceWorkItem $serviceWorkItem,
        CurrentOrganization $currentOrganization
    ): View {
        $this->guardWork(
            $request,
            $serviceOrder,
            $serviceWorkItem,
            $currentOrganization
        );
        abort_unless(
            $serviceWorkItem->execution_mode
                === ServiceWorkExecutionMode::External
            && $serviceWorkItem->status === ServiceWorkStatus::Planned
            && $serviceOrder->status === ServiceOrderStatus::InProgress,
            409
        );

        return $this->custodyView(
            $serviceOrder,
            $serviceWorkItem,
            'dispatch'
        );
    }

    public function storeDispatch(
        StoreServiceWorkCustodyRequest $request,
        ServiceOrder $serviceOrder,
        ServiceWorkItem $serviceWorkItem,
        CurrentOrganization $currentOrganization,
        ServiceWorkManager $manager
    ): RedirectResponse {
        $this->guardWork(
            $request,
            $serviceOrder,
            $serviceWorkItem,
            $currentOrganization
        );
        $validated = $request->validated();

        try {
            $manager->dispatchExternal(
                new ServiceWorkCustodyData(
                    serviceWorkItemId: $serviceWorkItem->id,
                    conditionNotes: $validated['condition_notes'],
                    accessoriesSnapshot: $validated['accessories_snapshot'],
                    idempotencyKey: $validated['idempotency_key']
                ),
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors([
                'work' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('service-orders.show', $serviceOrder)
            ->with(
                'success',
                'El equipo fue entregado al especialista externo.'
            );
    }

    public function createReturn(
        Request $request,
        ServiceOrder $serviceOrder,
        ServiceWorkItem $serviceWorkItem,
        CurrentOrganization $currentOrganization
    ): View {
        $this->guardWork(
            $request,
            $serviceOrder,
            $serviceWorkItem,
            $currentOrganization
        );
        abort_unless(
            $serviceWorkItem->execution_mode
                === ServiceWorkExecutionMode::External
            && $serviceWorkItem->status === ServiceWorkStatus::WithProvider
            && $serviceOrder->status
                === ServiceOrderStatus::WithExternalProvider,
            409
        );

        return $this->custodyView(
            $serviceOrder,
            $serviceWorkItem,
            'return'
        );
    }

    public function storeReturn(
        StoreServiceWorkCustodyRequest $request,
        ServiceOrder $serviceOrder,
        ServiceWorkItem $serviceWorkItem,
        CurrentOrganization $currentOrganization,
        ServiceWorkManager $manager
    ): RedirectResponse {
        $this->guardWork(
            $request,
            $serviceOrder,
            $serviceWorkItem,
            $currentOrganization
        );
        $validated = $request->validated();

        try {
            $manager->returnExternal(
                new ServiceWorkCustodyData(
                    serviceWorkItemId: $serviceWorkItem->id,
                    conditionNotes: $validated['condition_notes'],
                    accessoriesSnapshot: $validated['accessories_snapshot'],
                    idempotencyKey: $validated['idempotency_key']
                ),
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors([
                'work' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('service-orders.show', $serviceOrder)
            ->with(
                'success',
                'El equipo retornó del especialista y recuperó custodia interna.'
            );
    }

    public function createReport(
        Request $request,
        ServiceOrder $serviceOrder,
        ServiceWorkItem $serviceWorkItem,
        CurrentOrganization $currentOrganization
    ): View {
        $this->guardWork(
            $request,
            $serviceOrder,
            $serviceWorkItem,
            $currentOrganization
        );
        abort_unless(
            $serviceWorkItem->status === ServiceWorkStatus::InProgress
            && $serviceOrder->status === ServiceOrderStatus::InProgress
            && ! $serviceWorkItem->report()->exists(),
            409
        );

        $serviceWorkItem->load([
            'provider',
            'assignedUser',
            'partRequirements.product',
        ]);

        return view('service-orders.work.report', [
            'order' => $serviceOrder->load(['asset', 'intake']),
            'work' => $serviceWorkItem,
            'outcomes' => ServiceWorkOutcome::cases(),
            'idempotencyKey' => 'service-ui:work-report:'.Str::uuid(),
        ]);
    }

    public function storeReport(
        StoreServiceWorkReportRequest $request,
        ServiceOrder $serviceOrder,
        ServiceWorkItem $serviceWorkItem,
        CurrentOrganization $currentOrganization,
        ServiceWorkManager $manager
    ): RedirectResponse {
        $this->guardWork(
            $request,
            $serviceOrder,
            $serviceWorkItem,
            $currentOrganization
        );
        $validated = $request->validated();

        try {
            $report = $manager->report(
                new ServiceWorkReportData(
                    serviceWorkItemId: $serviceWorkItem->id,
                    outcome: ServiceWorkOutcome::from(
                        $validated['outcome']
                    ),
                    resultSummary: $validated['result_summary'],
                    workPerformed: $validated['work_performed'],
                    idempotencyKey: $validated['idempotency_key'],
                    unresolvedReason: $validated['unresolved_reason'] ?? null,
                    warrantyDays: $validated['warranty_days'] ?? null,
                    warrantyTerms: $validated['warranty_terms'] ?? null
                ),
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors([
                'work' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('service-orders.show', $serviceOrder)
            ->with(
                'success',
                'Resultado técnico registrado: '.$report->outcome->label().'.'
            );
    }

    private function custodyView(
        ServiceOrder $serviceOrder,
        ServiceWorkItem $serviceWorkItem,
        string $direction
    ): View {
        $serviceOrder->load(['asset', 'intake']);
        $serviceWorkItem->load([
            'provider',
            'custodyLinks.custodyEvent',
        ]);
        $latestCustody = $serviceOrder->custodyEvents()
            ->latest('occurred_at')
            ->latest('id')
            ->first();

        return view('service-orders.work.custody', [
            'order' => $serviceOrder,
            'work' => $serviceWorkItem,
            'direction' => $direction,
            'latestCustody' => $latestCustody,
            'idempotencyKey' => 'service-ui:work-'.$direction.':'.Str::uuid(),
        ]);
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
    ): void {
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
    }

    private function approvedDecision(
        int $organizationId,
        ServiceOrder $serviceOrder
    ): ServiceQuoteDecision {
        $decision = ServiceQuoteDecision::query()
            ->forOrganization($organizationId)
            ->where('decision', ServiceQuoteDecisionType::Approved->value)
            ->whereNotNull('service_quote_option_id')
            ->whereHas(
                'quote',
                fn ($query) => $query->where(
                    'service_order_id',
                    $serviceOrder->id
                )
            )
            ->with('selectedOption.lines')
            ->latest('id')
            ->first();

        abort_unless($decision?->selectedOption, 409);

        return $decision;
    }

    private function warrantyResolution(
        ServiceOrder $serviceOrder
    ): ?ServiceWarrantyClaimResolution {
        $claim = $serviceOrder->warrantyClaimAsCorrective()
            ->with('resolution')
            ->first();

        if (! $claim) {
            return null;
        }

        abort_unless(
            $claim->resolution
            && $claim->resolution->outcome->authorizesCorrectiveWork(),
            409
        );

        return $claim->resolution;
    }

    /** @return Collection<int, User> */
    private function members(int $organizationId): Collection
    {
        return OrganizationMembership::query()
            ->with('user')
            ->where('organization_id', $organizationId)
            ->where('active', true)
            ->orderBy('id')
            ->get()
            ->pluck('user')
            ->filter()
            ->sortBy('name')
            ->values();
    }
}
