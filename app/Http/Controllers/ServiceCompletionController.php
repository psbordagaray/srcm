<?php

namespace App\Http\Controllers;

use App\Domain\Service\ServiceCompletionManager;
use App\Domain\Service\ServiceDeliveryData;
use App\Domain\Service\ServiceQualityCheckData;
use App\Domain\Service\ServiceQualityInspectionData;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\ServiceOrderStatus;
use App\Enums\ServiceQualityOutcome;
use App\Http\Requests\StoreServiceDeliveryRequest;
use App\Http\Requests\StoreServiceQualityInspectionRequest;
use App\Models\BusinessParty;
use App\Models\ServiceOrder;
use App\Models\ServiceQualityInspection;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ServiceCompletionController extends Controller
{
    /**
     * @var list<array{code: string, label: string}>
     */
    private const QUALITY_CHECKS = [
        [
            'code' => 'power',
            'label' => 'Encendido y estabilidad',
        ],
        [
            'code' => 'charging',
            'label' => 'Carga y alimentación',
        ],
        [
            'code' => 'primary_function',
            'label' => 'Función principal del equipo',
        ],
        [
            'code' => 'connectivity',
            'label' => 'Conectividad y comunicaciones',
        ],
        [
            'code' => 'physical_condition',
            'label' => 'Condición física posterior al trabajo',
        ],
        [
            'code' => 'accessories',
            'label' => 'Accesorios y elementos en custodia',
        ],
    ];

    public function createQuality(
        Request $request,
        ServiceOrder $serviceOrder,
        CurrentOrganization $currentOrganization
    ): View {
        $this->guardOrder(
            $request,
            $serviceOrder,
            $currentOrganization
        );

        abort_unless(
            $serviceOrder->status === ServiceOrderStatus::QualityControl,
            409
        );

        $serviceOrder->load([
            'asset',
            'intake',
            'workItems.report',
            'partRequirements.product',
            'qualityInspections',
        ]);

        return view('service-orders.quality.create', [
            'order' => $serviceOrder,
            'qualityChecks' => self::QUALITY_CHECKS,
            'idempotencyKey' => 'service-ui:quality-inspection:'.Str::uuid(),
        ]);
    }

    public function storeQuality(
        StoreServiceQualityInspectionRequest $request,
        ServiceOrder $serviceOrder,
        CurrentOrganization $currentOrganization,
        ServiceCompletionManager $manager
    ): RedirectResponse {
        $this->guardOrder(
            $request,
            $serviceOrder,
            $currentOrganization
        );
        $validated = $request->validated();
        $labels = collect(self::QUALITY_CHECKS)
            ->pluck('label', 'code');

        try {
            $inspection = $manager->inspect(
                new ServiceQualityInspectionData(
                    serviceOrderId: $serviceOrder->id,
                    checks: collect($validated['checks'])
                        ->map(
                            fn (array $check): ServiceQualityCheckData => new ServiceQualityCheckData(
                                code: $check['code'],
                                label: (string) $labels->get(
                                    $check['code']
                                ),
                                passed: $check['passed'],
                                notes: $check['notes'] ?? null
                            )
                        )
                        ->values()
                        ->all(),
                    conditionNotes: $validated['condition_notes'],
                    accessoriesSnapshot: $validated['accessories_snapshot'],
                    idempotencyKey: $validated['idempotency_key'],
                    reworkReason: $validated['rework_reason'] ?? null,
                    notes: $validated['notes'] ?? null
                ),
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors([
                'completion' => $exception->getMessage(),
            ]);
        }

        $message = $inspection->outcome
            === ServiceQualityOutcome::Approved
                ? 'Control de calidad aprobado. La orden quedó lista para entregar.'
                : 'Control rechazado. La orden volvió a trabajo para retrabajo.';

        return redirect()
            ->route('service-orders.show', $serviceOrder)
            ->with('success', $message);
    }

    public function createDelivery(
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
            $serviceOrder->status === ServiceOrderStatus::ReadyForDelivery,
            409
        );

        $inspection = $this->latestApprovedInspection(
            $organizationId,
            $serviceOrder
        );

        $serviceOrder->load([
            'asset',
            'intake',
            'owner',
            'custodyEvents',
        ]);

        return view('service-orders.delivery.create', [
            'order' => $serviceOrder,
            'inspection' => $inspection,
            'recipients' => BusinessParty::query()
                ->forOrganization($organizationId)
                ->orderBy('name')
                ->get(['id', 'name', 'party_type']),
            'idempotencyKey' => 'service-ui:delivery:'.Str::uuid(),
        ]);
    }

    public function storeDelivery(
        StoreServiceDeliveryRequest $request,
        ServiceOrder $serviceOrder,
        CurrentOrganization $currentOrganization,
        ServiceCompletionManager $manager
    ): RedirectResponse {
        $organizationId = $this->guardOrder(
            $request,
            $serviceOrder,
            $currentOrganization
        );
        $validated = $request->validated();
        $inspection = $this->latestApprovedInspection(
            $organizationId,
            $serviceOrder
        );

        try {
            $delivery = $manager->deliver(
                new ServiceDeliveryData(
                    serviceOrderId: $serviceOrder->id,
                    serviceQualityInspectionId: $inspection->id,
                    recipientName: $validated['recipient_name'],
                    conditionNotes: $validated['condition_notes'],
                    accessoriesSnapshot: $validated['accessories_snapshot'],
                    customerConformity: $validated['customer_conformity'],
                    idempotencyKey: $validated['idempotency_key'],
                    recipientBusinessPartyId: $validated[
                            'recipient_business_party_id'
                        ] ?? null,
                    recipientDocument: $validated['recipient_document'] ?? null,
                    notes: $validated['notes'] ?? null,
                    deliveredAt: isset($validated['delivered_at'])
                        ? CarbonImmutable::parse(
                            $validated['delivered_at'],
                            config('app.timezone')
                        )
                        : null
                ),
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors([
                'completion' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('service-orders.show', $serviceOrder)
            ->with(
                'success',
                'Entrega registrada a '
                    .$delivery->recipient_name
                    .'. La custodia y las garantías quedaron confirmadas.'
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

    private function latestApprovedInspection(
        int $organizationId,
        ServiceOrder $serviceOrder
    ): ServiceQualityInspection {
        $inspection = ServiceQualityInspection::query()
            ->forOrganization($organizationId)
            ->where('service_order_id', $serviceOrder->id)
            ->latest('id')
            ->first();

        abort_unless(
            $inspection
            && $inspection->outcome === ServiceQualityOutcome::Approved,
            409
        );

        return $inspection;
    }
}
