<?php

namespace App\Http\Controllers;

use App\Domain\Service\ServiceCancellationManager;
use App\Domain\Service\ServiceCancellationRequestData;
use App\Domain\Service\ServiceCancellationResolutionData;
use App\Domain\Service\ServiceCancellationReturnData;
use App\Domain\Service\ServiceWorkCustodyData;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\ServiceCancellationFinancialOutcome;
use App\Enums\ServiceCancellationReason;
use App\Enums\ServiceOrderStatus;
use App\Enums\ServiceWorkExecutionMode;
use App\Enums\ServiceWorkStatus;
use App\Http\Requests\RecallServiceCancellationCustodyRequest;
use App\Http\Requests\RequestServiceCancellationRequest;
use App\Http\Requests\ResolveServiceCancellationRequest;
use App\Http\Requests\ReturnServiceCancellationRequest;
use App\Models\ServiceCancellationRequest;
use App\Models\ServiceCancellationResolution;
use App\Models\ServiceOrder;
use App\Models\ServiceWorkItem;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class ServiceCancellationController extends Controller
{
    public function createRequest(
        Request $request,
        ServiceOrder $serviceOrder,
        CurrentOrganization $currentOrganization
    ): View {
        $this->guardOrder($request, $serviceOrder, $currentOrganization);
        abort_unless($serviceOrder->canRequestCancellation(), 409);

        $serviceOrder->load([
            'asset',
            'intake',
            'customer',
            'cancellationRequest',
        ]);
        abort_if($serviceOrder->cancellationRequest !== null, 409);

        return view('service-orders.cancellation.request', [
            'order' => $serviceOrder,
            'reasons' => ServiceCancellationReason::cases(),
            'idempotencyKey' => 'service-ui:cancellation-request:'.Str::uuid(),
        ]);
    }

    public function storeRequest(
        RequestServiceCancellationRequest $request,
        ServiceOrder $serviceOrder,
        CurrentOrganization $currentOrganization,
        ServiceCancellationManager $manager
    ): RedirectResponse {
        $this->guardOrder($request, $serviceOrder, $currentOrganization);
        $validated = $request->validated();

        try {
            $manager->request(
                new ServiceCancellationRequestData(
                    serviceOrderId: $serviceOrder->id,
                    reason: ServiceCancellationReason::from(
                        $validated['reason']
                    ),
                    requesterName: $validated['requester_name'],
                    channel: $validated['channel'],
                    idempotencyKey: $validated['idempotency_key'],
                    requesterBusinessPartyId: $validated['requester_business_party_id'] ?? null,
                    customerReference: $validated['customer_reference'] ?? null,
                    details: $validated['details'] ?? null
                ),
                $request->user()
            );
        } catch (DomainException $exception) {
            return $this->domainFailure($exception);
        }

        return redirect()
            ->route('service-orders.show', $serviceOrder)
            ->with('success', 'Solicitud de cancelación registrada.');
    }

    public function createRecall(
        Request $request,
        ServiceOrder $serviceOrder,
        ServiceWorkItem $serviceWorkItem,
        CurrentOrganization $currentOrganization
    ): View {
        $this->guardWorkItem(
            $request,
            $serviceOrder,
            $serviceWorkItem,
            $currentOrganization
        );
        abort_unless(
            $serviceOrder->status === ServiceOrderStatus::CancellationPending
                && $serviceWorkItem->execution_mode
                    === ServiceWorkExecutionMode::External
                && $serviceWorkItem->status === ServiceWorkStatus::WithProvider,
            409
        );

        $serviceOrder->load(['asset', 'intake']);
        $serviceWorkItem->load('provider');

        return view('service-orders.cancellation.recall', [
            'order' => $serviceOrder,
            'work' => $serviceWorkItem,
            'idempotencyKey' => 'service-ui:cancellation-recall:'.Str::uuid(),
        ]);
    }

    public function storeRecall(
        RecallServiceCancellationCustodyRequest $request,
        ServiceOrder $serviceOrder,
        ServiceWorkItem $serviceWorkItem,
        CurrentOrganization $currentOrganization,
        ServiceCancellationManager $manager
    ): RedirectResponse {
        $this->guardWorkItem(
            $request,
            $serviceOrder,
            $serviceWorkItem,
            $currentOrganization
        );
        $validated = $request->validated();

        try {
            $manager->recallExternal(
                new ServiceWorkCustodyData(
                    serviceWorkItemId: $serviceWorkItem->id,
                    conditionNotes: $validated['condition_notes'],
                    accessoriesSnapshot: $validated['accessories_snapshot'],
                    idempotencyKey: $validated['idempotency_key']
                ),
                $request->user()
            );
        } catch (DomainException $exception) {
            return $this->domainFailure($exception);
        }

        return redirect()
            ->route('service-orders.show', $serviceOrder)
            ->with(
                'success',
                'El equipo retornó del especialista y el trabajo externo quedó cancelado.'
            );
    }

    public function createResolution(
        Request $request,
        ServiceOrder $serviceOrder,
        ServiceCancellationRequest $serviceCancellationRequest,
        CurrentOrganization $currentOrganization
    ): View {
        $this->guardCancellationRequest(
            $request,
            $serviceOrder,
            $serviceCancellationRequest,
            $currentOrganization
        );
        abort_unless(
            $serviceOrder->status === ServiceOrderStatus::CancellationPending,
            409
        );

        $serviceCancellationRequest->load([
            'requester',
            'requestedBy',
            'resolution',
        ]);
        abort_if($serviceCancellationRequest->resolution !== null, 409);
        $serviceOrder->load([
            'asset',
            'intake',
            'workItems.provider',
            'custodyEvents',
        ]);

        return view('service-orders.cancellation.resolve', [
            'order' => $serviceOrder,
            'cancellation' => $serviceCancellationRequest,
            'outcomes' => ServiceCancellationFinancialOutcome::cases(),
            'idempotencyKey' => 'service-ui:cancellation-resolution:'.Str::uuid(),
        ]);
    }

    public function storeResolution(
        ResolveServiceCancellationRequest $request,
        ServiceOrder $serviceOrder,
        ServiceCancellationRequest $serviceCancellationRequest,
        CurrentOrganization $currentOrganization,
        ServiceCancellationManager $manager
    ): RedirectResponse {
        $this->guardCancellationRequest(
            $request,
            $serviceOrder,
            $serviceCancellationRequest,
            $currentOrganization
        );
        $validated = $request->validated();

        try {
            $manager->resolve(
                new ServiceCancellationResolutionData(
                    serviceCancellationRequestId: $serviceCancellationRequest->id,
                    financialOutcome: ServiceCancellationFinancialOutcome::from(
                        $validated['financial_outcome']
                    ),
                    workDisposition: $validated['work_disposition'],
                    partsDisposition: $validated['parts_disposition'],
                    financialDisposition: $validated['financial_disposition'],
                    returnConditionNotes: $validated['return_condition_notes'],
                    accessoriesSnapshot: $validated['accessories_snapshot'],
                    idempotencyKey: $validated['idempotency_key'],
                    currencyCode: $validated['currency_code'],
                    customerChargeMinor: filled(
                        $validated['customer_charge'] ?? null
                    )
                        ? $this->minorAmount($validated['customer_charge'])
                        : 0,
                    customerAcceptanceReference: $validated['customer_acceptance_reference'] ?? null,
                    notes: $validated['notes'] ?? null
                ),
                $request->user()
            );
        } catch (DomainException $exception) {
            return $this->domainFailure($exception);
        }

        return redirect()
            ->route('service-orders.show', $serviceOrder)
            ->with(
                'success',
                'Cancelación resuelta. La orden quedó lista para devolver.'
            );
    }

    public function createReturn(
        Request $request,
        ServiceOrder $serviceOrder,
        ServiceCancellationResolution $serviceCancellationResolution,
        CurrentOrganization $currentOrganization
    ): View {
        $this->guardResolution(
            $request,
            $serviceOrder,
            $serviceCancellationResolution,
            $currentOrganization
        );
        abort_unless(
            $serviceOrder->status === ServiceOrderStatus::ReadyForReturn,
            409
        );

        $serviceCancellationResolution->load([
            'request.requester',
            'returnRecord',
        ]);
        abort_if($serviceCancellationResolution->returnRecord !== null, 409);
        $serviceOrder->load(['asset', 'intake', 'customer']);

        return view('service-orders.cancellation.return', [
            'order' => $serviceOrder,
            'resolution' => $serviceCancellationResolution,
            'idempotencyKey' => 'service-ui:cancellation-return:'.Str::uuid(),
        ]);
    }

    public function storeReturn(
        ReturnServiceCancellationRequest $request,
        ServiceOrder $serviceOrder,
        ServiceCancellationResolution $serviceCancellationResolution,
        CurrentOrganization $currentOrganization,
        ServiceCancellationManager $manager
    ): RedirectResponse {
        $this->guardResolution(
            $request,
            $serviceOrder,
            $serviceCancellationResolution,
            $currentOrganization
        );
        $validated = $request->validated();

        try {
            $manager->returnAsset(
                new ServiceCancellationReturnData(
                    serviceCancellationResolutionId: $serviceCancellationResolution->id,
                    recipientName: $validated['recipient_name'],
                    conditionNotes: $validated['condition_notes'],
                    accessoriesSnapshot: $validated['accessories_snapshot'],
                    idempotencyKey: $validated['idempotency_key'],
                    recipientBusinessPartyId: $validated['recipient_business_party_id'] ?? null,
                    recipientDocument: $validated['recipient_document'] ?? null,
                    notes: $validated['notes'] ?? null
                ),
                $request->user()
            );
        } catch (DomainException $exception) {
            return $this->domainFailure($exception);
        }

        return redirect()
            ->route('service-orders.show', $serviceOrder)
            ->with(
                'success',
                'Equipo devuelto y orden cancelada definitivamente.'
            );
    }

    private function guardOrder(
        Request $request,
        ServiceOrder $serviceOrder,
        CurrentOrganization $currentOrganization
    ): void {
        abort_unless(
            (int) $serviceOrder->organization_id
                === $currentOrganization->id($request->user()),
            404
        );
    }

    private function guardWorkItem(
        Request $request,
        ServiceOrder $serviceOrder,
        ServiceWorkItem $serviceWorkItem,
        CurrentOrganization $currentOrganization
    ): void {
        $this->guardOrder($request, $serviceOrder, $currentOrganization);

        abort_unless(
            (int) $serviceWorkItem->organization_id
                === (int) $serviceOrder->organization_id
                && (int) $serviceWorkItem->service_order_id
                    === (int) $serviceOrder->id,
            404
        );
    }

    private function guardCancellationRequest(
        Request $request,
        ServiceOrder $serviceOrder,
        ServiceCancellationRequest $serviceCancellationRequest,
        CurrentOrganization $currentOrganization
    ): void {
        $this->guardOrder($request, $serviceOrder, $currentOrganization);

        abort_unless(
            (int) $serviceCancellationRequest->organization_id
                === (int) $serviceOrder->organization_id
                && (int) $serviceCancellationRequest->service_order_id
                    === (int) $serviceOrder->id,
            404
        );
    }

    private function guardResolution(
        Request $request,
        ServiceOrder $serviceOrder,
        ServiceCancellationResolution $serviceCancellationResolution,
        CurrentOrganization $currentOrganization
    ): void {
        $this->guardOrder($request, $serviceOrder, $currentOrganization);
        $serviceCancellationResolution->loadMissing('request');

        abort_unless(
            (int) $serviceCancellationResolution->organization_id
                === (int) $serviceOrder->organization_id
                && (int) $serviceCancellationResolution->request?->service_order_id
                    === (int) $serviceOrder->id,
            404
        );
    }

    private function domainFailure(
        DomainException $exception
    ): RedirectResponse {
        return back()->withInput()->withErrors([
            'cancellation' => $exception->getMessage(),
        ]);
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
                'El cargo contiene una fracción menor a un centavo.',
                previous: $exception
            );
        }
    }
}
