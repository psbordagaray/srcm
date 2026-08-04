<?php

namespace Tests\Feature\Service;

use App\Domain\Service\ServiceAssessmentManager;
use App\Domain\Service\ServiceAssetIdentifierData;
use App\Domain\Service\ServiceCancellationManager;
use App\Domain\Service\ServiceCancellationRequestData;
use App\Domain\Service\ServiceCancellationResolutionData;
use App\Domain\Service\ServiceCancellationReturnData;
use App\Domain\Service\ServiceDiagnosticData;
use App\Domain\Service\ServiceDiagnosticFindingData;
use App\Domain\Service\ServiceOrderIntakeData;
use App\Domain\Service\ServiceOrderIntakeManager;
use App\Domain\Service\ServiceQuoteData;
use App\Domain\Service\ServiceQuoteDecisionData;
use App\Domain\Service\ServiceQuoteLineData;
use App\Domain\Service\ServiceQuoteOptionData;
use App\Domain\Service\ServiceWorkCustodyData;
use App\Domain\Service\ServiceWorkItemData;
use App\Domain\Service\ServiceWorkManager;
use App\Enums\ServiceAssetType;
use App\Enums\ServiceCancellationFinancialOutcome;
use App\Enums\ServiceCancellationReason;
use App\Enums\ServiceFindingSeverity;
use App\Enums\ServiceIdentifierType;
use App\Enums\ServiceOrderStatus;
use App\Enums\ServiceQuoteDecisionType;
use App\Enums\ServiceQuoteLineType;
use App\Enums\ServiceWorkExecutionMode;
use App\Enums\ServiceWorkStatus;
use App\Enums\UserRole;
use App\Models\BusinessParty;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ServiceCancellationRequest;
use App\Models\ServiceOrder;
use App\Models\ServiceQuoteOption;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ServiceCancellationFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_schema_states_and_permissions_are_explicit(): void
    {
        $this->assertTrue(Schema::hasColumns(
            'service_cancellation_requests',
            [
                'service_order_id',
                'reason',
                'order_status_snapshot',
                'has_started_work',
                'has_part_purchases',
                'has_part_consumptions',
                'has_external_custody',
                'has_registered_payments',
                'exposure_snapshot',
                'idempotency_key',
            ]
        ));
        $this->assertTrue(Schema::hasTable(
            'service_cancellation_resolutions'
        ));
        $this->assertTrue(Schema::hasTable(
            'service_cancellation_returns'
        ));
        $this->assertSame(
            'Cancelación solicitada',
            ServiceOrderStatus::CancellationPending->label()
        );
        $this->assertSame(
            'Lista para devolver',
            ServiceOrderStatus::ReadyForReturn->label()
        );
        $this->assertSame(
            'Cancelada y devuelta',
            ServiceOrderStatus::Cancelled->label()
        );
        $this->assertTrue(
            UserRole::Operator->canRequestServiceCancellation()
        );
        $this->assertFalse(
            UserRole::Operator->canResolveServiceCancellation()
        );
        $this->assertTrue(
            UserRole::Admin->canResolveServiceCancellation()
        );
        $this->assertFalse(
            UserRole::Viewer->canRequestServiceCancellation()
        );
    }

    public function test_approved_repair_can_be_cancelled_and_returned_without_charge(): void
    {
        [$organization, $operator, $order, , $customer] =
            $this->approvedOrder('replacement');
        $admin = $this->user($organization, UserRole::Admin);
        $manager = app(ServiceCancellationManager::class);
        $requestData = new ServiceCancellationRequestData(
            serviceOrderId: $order->id,
            reason: ServiceCancellationReason::ReplacementDevice,
            requesterName: $customer->name,
            channel: 'WhatsApp',
            idempotencyKey: 'service:cancel:replacement:request',
            requesterBusinessPartyId: $customer->id,
            customerReference: '+54 9 3447 000001',
            details: 'Le regalaron otro teléfono y ya no desea repararlo.'
        );

        $request = $manager->request($requestData, $operator);
        $retry = $manager->request($requestData, $operator);

        $this->assertSame($request->id, $retry->id);
        $this->assertSame(
            ServiceOrderStatus::InProgress,
            $request->order_status_snapshot
        );
        $this->assertFalse($request->has_started_work);
        $this->assertFalse($request->has_part_purchases);
        $this->assertSame(
            ServiceOrderStatus::CancellationPending,
            $order->fresh()->status
        );
        $this->assertDatabaseHas('service_quote_decisions', [
            'decision' => ServiceQuoteDecisionType::Approved->value,
        ]);

        $resolutionData = $this->resolutionData(
            $request,
            'service:cancel:replacement:resolution'
        );
        $resolution = $manager->resolve($resolutionData, $admin);
        $resolutionRetry = $manager->resolve($resolutionData, $admin);

        $this->assertSame($resolution->id, $resolutionRetry->id);
        $this->assertSame(
            ServiceOrderStatus::ReadyForReturn,
            $order->fresh()->status
        );

        $returnData = new ServiceCancellationReturnData(
            serviceCancellationResolutionId: $resolution->id,
            recipientName: $customer->name,
            conditionNotes: 'Se devuelve en la condición documentada.',
            accessoriesSnapshot: 'Equipo y funda negra.',
            idempotencyKey: 'service:cancel:replacement:return',
            recipientBusinessPartyId: $customer->id
        );
        $return = $manager->returnAsset($returnData, $operator);
        $returnRetry = $manager->returnAsset($returnData, $operator);

        $this->assertSame($return->id, $returnRetry->id);
        $this->assertSame(
            ServiceOrderStatus::Cancelled,
            $order->fresh()->status
        );
        $this->assertSame(
            'delivered',
            $return->custodyEvent->event_type->value
        );
        $this->assertDatabaseCount('service_cancellation_requests', 1);
        $this->assertDatabaseCount('service_cancellation_resolutions', 1);
        $this->assertDatabaseCount('service_cancellation_returns', 1);
    }

    public function test_revised_promise_is_preserved_as_customer_reason(): void
    {
        [$organization, $operator, $order, , $customer] =
            $this->approvedOrder('delay');
        $request = app(ServiceCancellationManager::class)->request(
            new ServiceCancellationRequestData(
                serviceOrderId: $order->id,
                reason: ServiceCancellationReason::RevisedPromiseRejected,
                requesterName: $customer->name,
                channel: 'WhatsApp',
                idempotencyKey: 'service:cancel:delay:request',
                requesterBusinessPartyId: $customer->id,
                details: 'Rechazó una demora de dos días comunicada por el local.'
            ),
            $operator
        );

        $this->assertSame(
            ServiceCancellationReason::RevisedPromiseRejected,
            $request->reason
        );
        $this->assertSame(
            ServiceOrderStatus::InProgress,
            $request->order_status_snapshot
        );
        $this->assertStringContainsString('dos días', $request->details);
        $this->assertSame(
            ServiceOrderStatus::CancellationPending,
            $order->fresh()->status
        );
    }

    public function test_started_internal_work_is_frozen_and_visible_in_snapshot(): void
    {
        [$organization, $operator, $order, $option, $customer] =
            $this->approvedOrder('internal');
        $admin = $this->user($organization, UserRole::Admin);
        $workManager = app(ServiceWorkManager::class);
        $work = $workManager->plan(
            new ServiceWorkItemData(
                serviceOrderId: $order->id,
                serviceQuoteOptionId: $option->id,
                title: 'Cambio de módulo',
                description: 'Desarme y reemplazo del conjunto frontal.',
                executionMode: ServiceWorkExecutionMode::Internal,
                idempotencyKey: 'service:cancel:internal:work',
                assignedUserId: $operator->id
            ),
            $operator
        );
        $workManager->startInternal(
            $work->id,
            'service:cancel:internal:start',
            $operator
        );
        $manager = app(ServiceCancellationManager::class);
        $request = $manager->request(
            new ServiceCancellationRequestData(
                serviceOrderId: $order->id,
                reason: ServiceCancellationReason::CustomerChangedMind,
                requesterName: $customer->name,
                channel: 'Mostrador',
                idempotencyKey: 'service:cancel:internal:request',
                requesterBusinessPartyId: $customer->id
            ),
            $operator
        );

        $this->assertTrue($request->has_started_work);
        $this->assertSame(
            1,
            $request->exposure_snapshot['work_counts']['in_progress']
        );
        $this->assertSame(
            ServiceWorkStatus::Cancelled,
            $work->fresh()->status
        );

        $resolution = $manager->resolve(
            new ServiceCancellationResolutionData(
                serviceCancellationRequestId: $request->id,
                financialOutcome:
                    ServiceCancellationFinancialOutcome::BusinessAbsorbsCosts,
                workDisposition: 'Trabajo detenido antes de instalar piezas.',
                partsDisposition: 'No existían repuestos comprados.',
                financialDisposition: 'El comercio absorbe el tiempo aplicado.',
                returnConditionNotes: 'Equipo rearmado y sin piezas nuevas.',
                accessoriesSnapshot: 'Equipo y funda negra.',
                idempotencyKey: 'service:cancel:internal:resolution'
            ),
            $admin
        );

        $this->assertSame(
            ServiceCancellationFinancialOutcome::BusinessAbsorbsCosts,
            $resolution->financial_outcome
        );
    }

    public function test_external_custody_must_return_before_resolution(): void
    {
        [$organization, $operator, $order, $option, $customer] =
            $this->approvedOrder('external');
        $admin = $this->user($organization, UserRole::Admin);
        $provider = $this->party($organization, 'Jorge electrónica');
        $workManager = app(ServiceWorkManager::class);
        $work = $workManager->plan(
            new ServiceWorkItemData(
                serviceOrderId: $order->id,
                serviceQuoteOptionId: $option->id,
                title: 'Reparación electrónica especializada',
                description: 'Trabajo derivado al colega de confianza.',
                executionMode: ServiceWorkExecutionMode::External,
                idempotencyKey: 'service:cancel:external:work',
                providerBusinessPartyId: $provider->id
            ),
            $operator
        );
        $workManager->dispatchExternal(
            new ServiceWorkCustodyData(
                serviceWorkItemId: $work->id,
                conditionNotes: 'Equipo cerrado y documentado.',
                accessoriesSnapshot: 'Equipo sin accesorios.',
                idempotencyKey: 'service:cancel:external:dispatch'
            ),
            $operator
        );
        $manager = app(ServiceCancellationManager::class);
        $request = $manager->request(
            new ServiceCancellationRequestData(
                serviceOrderId: $order->id,
                reason: ServiceCancellationReason::CustomerChangedMind,
                requesterName: $customer->name,
                channel: 'Teléfono',
                idempotencyKey: 'service:cancel:external:request',
                requesterBusinessPartyId: $customer->id
            ),
            $operator
        );

        $this->assertTrue($request->has_external_custody);
        $this->assertSame(
            ServiceWorkStatus::WithProvider,
            $work->fresh()->status
        );
        $resolutionData = $this->resolutionData(
            $request,
            'service:cancel:external:resolution'
        );
        $this->assertDomainFailure(
            fn () => $manager->resolve($resolutionData, $admin)
        );

        $recall = $manager->recallExternal(
            new ServiceWorkCustodyData(
                serviceWorkItemId: $work->id,
                conditionNotes: 'Retorna sin intervención irreversible.',
                accessoriesSnapshot: 'Equipo sin accesorios.',
                idempotencyKey: 'service:cancel:external:recall'
            ),
            $operator
        );

        $this->assertSame('organization', $recall->custodyEvent->to_holder_type);
        $this->assertSame(
            ServiceWorkStatus::Cancelled,
            $work->fresh()->status
        );
        $resolution = $manager->resolve($resolutionData, $admin);
        $this->assertSame(
            ServiceOrderStatus::ReadyForReturn,
            $order->fresh()->status
        );
        $this->assertSame($request->id, $resolution->request->id);
    }

    public function test_charge_requires_admin_amount_and_customer_acceptance(): void
    {
        [$organization, $operator, $order, , $customer] =
            $this->approvedOrder('charge');
        $admin = $this->user($organization, UserRole::Admin);
        $viewer = $this->user($organization, UserRole::Viewer);
        $manager = app(ServiceCancellationManager::class);
        $requestData = new ServiceCancellationRequestData(
            serviceOrderId: $order->id,
            reason: ServiceCancellationReason::CustomerChangedMind,
            requesterName: $customer->name,
            channel: 'WhatsApp',
            idempotencyKey: 'service:cancel:charge:request',
            requesterBusinessPartyId: $customer->id
        );

        $this->assertDomainFailure(
            fn () => $manager->request($requestData, $viewer)
        );
        $request = $manager->request($requestData, $operator);
        $invalid = new ServiceCancellationResolutionData(
            serviceCancellationRequestId: $request->id,
            financialOutcome:
                ServiceCancellationFinancialOutcome::CustomerCharge,
            workDisposition: 'Trabajo detenido.',
            partsDisposition: 'Repuesto reservado para el cliente.',
            financialDisposition: 'Cargo por compromiso asumido.',
            returnConditionNotes: 'Equipo sin reparar.',
            accessoriesSnapshot: 'Equipo y funda.',
            idempotencyKey: 'service:cancel:charge:invalid',
            customerChargeMinor: 1500000
        );

        $this->assertDomainFailure(
            fn () => $manager->resolve($invalid, $admin)
        );
        $valid = new ServiceCancellationResolutionData(
            serviceCancellationRequestId: $request->id,
            financialOutcome:
                ServiceCancellationFinancialOutcome::CustomerCharge,
            workDisposition: 'Trabajo detenido.',
            partsDisposition: 'Repuesto reservado para el cliente.',
            financialDisposition: 'Cargo de cancelación acordado.',
            returnConditionNotes: 'Equipo sin reparar.',
            accessoriesSnapshot: 'Equipo y funda.',
            idempotencyKey: 'service:cancel:charge:valid',
            customerChargeMinor: 1500000,
            customerAcceptanceReference: 'WhatsApp 03/08/2026 20:10'
        );

        $this->assertDomainFailure(
            fn () => $manager->resolve($valid, $operator)
        );
        $resolution = $manager->resolve($valid, $admin);
        $this->assertSame(1500000, $resolution->customer_charge_minor);
    }

    public function test_database_guards_preserve_request_and_state_evidence(): void
    {
        [$organization, $operator, $order, , $customer] =
            $this->approvedOrder('guards');
        $request = app(ServiceCancellationManager::class)->request(
            new ServiceCancellationRequestData(
                serviceOrderId: $order->id,
                reason: ServiceCancellationReason::ReplacementDevice,
                requesterName: $customer->name,
                channel: 'Mostrador',
                idempotencyKey: 'service:cancel:guards:request',
                requesterBusinessPartyId: $customer->id
            ),
            $operator
        );

        $this->assertQueryRejected(
            fn () => DB::table('service_cancellation_requests')
                ->where('id', $request->id)
                ->update(['requester_name' => 'Nombre adulterado'])
        );
        $this->assertQueryRejected(
            fn () => DB::table('service_cancellation_requests')
                ->where('id', $request->id)
                ->delete()
        );
        $this->assertQueryRejected(
            fn () => DB::table('service_orders')
                ->where('id', $order->id)
                ->update(['status' => ServiceOrderStatus::Cancelled->value])
        );
    }

    public function test_database_rejects_a_false_exposure_snapshot(): void
    {
        [$organization, $operator, $order, $option, $customer] =
            $this->approvedOrder('false-snapshot');
        $workManager = app(ServiceWorkManager::class);
        $work = $workManager->plan(
            new ServiceWorkItemData(
                serviceOrderId: $order->id,
                serviceQuoteOptionId: $option->id,
                title: 'Trabajo ya iniciado',
                description: 'La exposición no puede ocultarse al cancelar.',
                executionMode: ServiceWorkExecutionMode::Internal,
                idempotencyKey: 'service:cancel:false-snapshot:work',
                assignedUserId: $operator->id
            ),
            $operator
        );
        $workManager->startInternal(
            $work->id,
            'service:cancel:false-snapshot:start',
            $operator
        );

        $this->assertQueryRejected(
            fn () => DB::table('service_cancellation_requests')->insert([
                'organization_id' => $organization->id,
                'service_order_id' => $order->id,
                'reason' => ServiceCancellationReason::CustomerChangedMind
                    ->value,
                'requester_business_party_id' => $customer->id,
                'requester_name' => $customer->name,
                'customer_reference' => null,
                'channel' => 'Mostrador',
                'details' => null,
                'order_status_snapshot' => $order->status->value,
                'has_started_work' => false,
                'has_part_purchases' => false,
                'has_part_consumptions' => false,
                'has_external_custody' => false,
                'has_registered_payments' => false,
                'exposure_snapshot' => json_encode([
                    'work_counts' => [],
                ], JSON_THROW_ON_ERROR),
                'requested_by_user_id' => $operator->id,
                'requested_at' => now(),
                'idempotency_key' =>
                    'service:cancel:false-snapshot:request',
                'fingerprint' => str_repeat('a', 64),
                'created_at' => now(),
                'updated_at' => now(),
            ])
        );
    }

    private function resolutionData(
        ServiceCancellationRequest $request,
        string $key
    ): ServiceCancellationResolutionData {
        return new ServiceCancellationResolutionData(
            serviceCancellationRequestId: $request->id,
            financialOutcome: ServiceCancellationFinancialOutcome::NoCharge,
            workDisposition: 'No se realizaron trabajos irreversibles.',
            partsDisposition: 'No existen repuestos pendientes.',
            financialDisposition: 'Cancelación sin cargo para el cliente.',
            returnConditionNotes: 'Equipo listo para devolver.',
            accessoriesSnapshot: 'Equipo y funda negra.',
            idempotencyKey: $key
        );
    }

    /**
     * @return array{
     *     Organization,
     *     User,
     *     ServiceOrder,
     *     ServiceQuoteOption,
     *     BusinessParty
     * }
     */
    private function approvedOrder(string $suffix): array
    {
        $organization = Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();
        $actor = $this->user($organization, UserRole::Operator);
        $customer = $this->party(
            $organization,
            'Cliente cancelación '.$suffix
        );
        $location = InventoryLocation::query()
            ->forOrganization($organization->id)
            ->where(
                'normalized_name',
                InventoryLocation::normalizeName('Recepción')
            )
            ->firstOrFail();
        $order = app(ServiceOrderIntakeManager::class)->create(
            new ServiceOrderIntakeData(
                assetType: ServiceAssetType::MobilePhone,
                brandName: 'Motorola',
                modelName: 'E22i',
                identifiers: [
                    new ServiceAssetIdentifierData(
                        ServiceIdentifierType::Imei,
                        str_pad(
                            sprintf('%u', crc32($suffix)),
                            15,
                            '0',
                            STR_PAD_LEFT
                        )
                    ),
                ],
                intakeLocationId: $location->id,
                customerReportedIssue: 'Pantalla rota por una caída.',
                idempotencyKey: 'service:intake:cancel:'.$suffix,
                customerBusinessPartyId: $customer->id,
                intakeObservations: 'Chasis con marcas documentadas.',
                receivedAccessories: 'Equipo y funda negra.'
            ),
            $actor
        );
        $assessment = app(ServiceAssessmentManager::class);
        $assessment->recordDiagnostic(
            new ServiceDiagnosticData(
                serviceOrderId: $order->id,
                summary: 'Módulo roto y táctil funcional.',
                recommendation: 'Cambiar módulo con marco.',
                findings: [
                    new ServiceDiagnosticFindingData(
                        ServiceFindingSeverity::Attention,
                        'Pantalla',
                        'El módulo presenta rotura visible.'
                    ),
                ],
                idempotencyKey: 'service:diagnostic:cancel:'.$suffix
            ),
            $actor
        );
        $quote = $assessment->issueQuote(
            new ServiceQuoteData(
                serviceOrderId: $order->id,
                options: [
                    new ServiceQuoteOptionData(
                        label: 'Solución integral',
                        lines: [
                            new ServiceQuoteLineData(
                                ServiceQuoteLineType::Part,
                                'Módulo con marco',
                                '1',
                                9500000
                            ),
                            new ServiceQuoteLineData(
                                ServiceQuoteLineType::Labor,
                                'Cambio y pruebas',
                                '1',
                                3500000
                            ),
                        ],
                        recommended: true
                    ),
                ],
                idempotencyKey: 'service:quote:cancel:'.$suffix
            ),
            $actor
        );
        $option = $quote->options->sole();
        $assessment->recordDecision(
            new ServiceQuoteDecisionData(
                serviceQuoteId: $quote->id,
                decision: ServiceQuoteDecisionType::Approved,
                customerName: $customer->name,
                channel: 'WhatsApp',
                idempotencyKey: 'service:decision:cancel:'.$suffix,
                serviceQuoteOptionId: $option->id
            ),
            $actor
        );

        return [$organization, $actor, $order, $option, $customer];
    }

    private function party(
        Organization $organization,
        string $name
    ): BusinessParty {
        return BusinessParty::query()->create([
            'organization_id' => $organization->id,
            'party_type' => BusinessParty::TYPE_PERSON,
            'name' => $name,
        ]);
    }

    private function user(
        Organization $organization,
        UserRole $role
    ): User {
        $user = User::factory()->create();
        $user->forceFill([
            'role' => $role,
            'current_organization_id' => $organization->id,
        ])->saveQuietly();

        OrganizationMembership::withoutEvents(
            fn () => OrganizationMembership::query()->updateOrCreate(
                [
                    'organization_id' => $organization->id,
                    'user_id' => $user->id,
                ],
                [
                    'role' => $role,
                    'active' => true,
                ]
            )
        );

        return $user;
    }

    private function assertDomainFailure(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Se esperaba una excepción de dominio.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }
    }

    private function assertQueryRejected(callable $callback): void
    {
        try {
            $callback();
            $this->fail('La base de datos aceptó una operación inválida.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
