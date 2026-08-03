<?php

namespace Tests\Feature\Service;

use App\Domain\Service\ServiceAssessmentManager;
use App\Domain\Service\ServiceAssetIdentifierData;
use App\Domain\Service\ServiceCompletionManager;
use App\Domain\Service\ServiceDeliveryData;
use App\Domain\Service\ServiceDiagnosticData;
use App\Domain\Service\ServiceDiagnosticFindingData;
use App\Domain\Service\ServiceOrderIntakeData;
use App\Domain\Service\ServiceOrderIntakeManager;
use App\Domain\Service\ServiceQualityCheckData;
use App\Domain\Service\ServiceQualityInspectionData;
use App\Domain\Service\ServiceQuoteData;
use App\Domain\Service\ServiceQuoteDecisionData;
use App\Domain\Service\ServiceQuoteLineData;
use App\Domain\Service\ServiceQuoteOptionData;
use App\Domain\Service\ServiceWorkItemData;
use App\Domain\Service\ServiceWorkManager;
use App\Domain\Service\ServiceWorkReportData;
use App\Enums\ServiceAssetType;
use App\Enums\ServiceCustodyEventType;
use App\Enums\ServiceFindingSeverity;
use App\Enums\ServiceIdentifierType;
use App\Enums\ServiceOrderStatus;
use App\Enums\ServiceQualityOutcome;
use App\Enums\ServiceQuoteDecisionType;
use App\Enums\ServiceQuoteLineType;
use App\Enums\ServiceWorkExecutionMode;
use App\Enums\ServiceWorkOutcome;
use App\Enums\UserRole;
use App\Models\BusinessParty;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ServiceOrder;
use App\Models\ServiceQuoteOption;
use App\Models\ServiceWorkItem;
use App\Models\ServiceWorkReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ServiceCompletionFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_schema_and_completion_permissions_are_explicit(): void
    {
        $this->assertTrue(Schema::hasColumns(
            'service_quality_inspections',
            [
                'organization_id',
                'service_order_id',
                'revision',
                'outcome',
                'checks',
                'failed_check_count',
            ]
        ));
        $this->assertTrue(Schema::hasColumns('service_deliveries', [
            'service_quality_inspection_id',
            'service_custody_event_id',
            'recipient_business_party_id',
            'customer_conformity',
            'delivered_at',
        ]));
        $this->assertTrue(Schema::hasColumns('service_warranty_grants', [
            'service_delivery_id',
            'service_work_report_id',
            'warranty_days',
            'starts_at',
            'expires_at',
        ]));

        $this->assertTrue(UserRole::Admin->canInspectServiceQuality());
        $this->assertTrue(UserRole::Operator->canInspectServiceQuality());
        $this->assertFalse(UserRole::Viewer->canInspectServiceQuality());
        $this->assertTrue(UserRole::Admin->canDeliverServiceOrders());
        $this->assertTrue(UserRole::Operator->canDeliverServiceOrders());
        $this->assertFalse(UserRole::Viewer->canDeliverServiceOrders());
    }

    public function test_approved_quality_allows_delivery_and_starts_warranty(): void
    {
        [$organization, $actor, $order, , , $report, $customer] =
            $this->completedOrder('approved');
        $manager = app(ServiceCompletionManager::class);
        $inspectionData = $this->approvedInspection(
            $order,
            'service:completion:quality:approved'
        );
        $inspection = $manager->inspect($inspectionData, $actor);
        $inspectionRetry = $manager->inspect($inspectionData, $actor);

        $this->assertSame($inspection->id, $inspectionRetry->id);
        $this->assertSame(ServiceQualityOutcome::Approved, $inspection->outcome);
        $this->assertSame(3, $inspection->check_count);
        $this->assertSame(0, $inspection->failed_check_count);
        $this->assertSame(
            ServiceOrderStatus::ReadyForDelivery,
            $order->fresh()->status
        );

        $deliveredAt = CarbonImmutable::instance(
            $inspection->inspected_at
        )->addSecond();
        $deliveryData = new ServiceDeliveryData(
            serviceOrderId: $order->id,
            serviceQualityInspectionId: $inspection->id,
            recipientName: $customer->name,
            conditionNotes: 'Equipo encendido, probado y sin daños nuevos.',
            accessoriesSnapshot: 'Equipo y funda entregados.',
            customerConformity: true,
            idempotencyKey: 'service:completion:delivery:approved',
            recipientBusinessPartyId: $customer->id,
            recipientDocument: 'DNI 30111222',
            notes: 'El cliente verificó el funcionamiento.',
            deliveredAt: $deliveredAt
        );
        $delivery = $manager->deliver($deliveryData, $actor);
        $deliveryRetry = $manager->deliver($deliveryData, $actor);

        $this->assertSame($delivery->id, $deliveryRetry->id);
        $this->assertSame(
            ServiceOrderStatus::Delivered,
            $order->fresh()->status
        );
        $this->assertSame(
            ServiceCustodyEventType::Delivered,
            $delivery->custodyEvent->event_type
        );
        $this->assertSame($customer->id, $delivery->recipient->id);
        $this->assertTrue($delivery->customer_conformity);
        $this->assertSame($organization->id, $delivery->organization_id);

        $grant = $delivery->warranties->sole();
        $this->assertSame($report->id, $grant->service_work_report_id);
        $this->assertSame(90, $grant->warranty_days);
        $this->assertSame(
            $deliveredAt->format('Y-m-d H:i:s'),
            $grant->starts_at->format('Y-m-d H:i:s')
        );
        $this->assertSame(
            $deliveredAt->addDays(90)->format('Y-m-d H:i:s'),
            $grant->expires_at->format('Y-m-d H:i:s')
        );
        $this->assertSame(
            'Garantía sobre mano de obra y componentes instalados.',
            $grant->coverage_terms
        );
        $this->assertDatabaseCount('service_deliveries', 1);
        $this->assertDatabaseCount('service_warranty_grants', 1);
    }

    public function test_failed_quality_returns_order_to_rework(): void
    {
        [, $actor, $order, $option] = $this->completedOrder('rework');
        $inspection = app(ServiceCompletionManager::class)->inspect(
            new ServiceQualityInspectionData(
                serviceOrderId: $order->id,
                checks: [
                    new ServiceQualityCheckData(
                        'power',
                        'Encendido estable',
                        true
                    ),
                    new ServiceQualityCheckData(
                        'audio',
                        'Audio de llamada',
                        false,
                        'El auricular presenta cortes.'
                    ),
                ],
                conditionNotes: 'El equipo conserva su estado físico.',
                accessoriesSnapshot: 'Equipo sin accesorios.',
                idempotencyKey: 'service:completion:quality:rework',
                reworkReason: 'Revisar auricular y repetir pruebas.'
            ),
            $actor
        );

        $this->assertSame(
            ServiceQualityOutcome::ReworkRequired,
            $inspection->outcome
        );
        $this->assertSame(1, $inspection->failed_check_count);
        $this->assertSame(
            ServiceOrderStatus::InProgress,
            $order->fresh()->status
        );
        $this->assertDomainFailure(
            fn () => app(ServiceCompletionManager::class)->deliver(
                new ServiceDeliveryData(
                    serviceOrderId: $order->id,
                    serviceQualityInspectionId: $inspection->id,
                    recipientName: 'Cliente rework',
                    conditionNotes: 'Sin cambios.',
                    accessoriesSnapshot: 'Equipo sin accesorios.',
                    customerConformity: true,
                    idempotencyKey: 'service:completion:delivery:rework'
                ),
                $actor
            )
        );

        $rework = app(ServiceWorkManager::class)->plan(
            new ServiceWorkItemData(
                serviceOrderId: $order->id,
                serviceQuoteOptionId: $option->id,
                title: 'Retrabajo de auricular',
                description: 'Corregir los cortes detectados en calidad.',
                executionMode: ServiceWorkExecutionMode::Internal,
                idempotencyKey: 'service:completion:rework:item',
                assignedUserId: $actor->id
            ),
            $actor
        );

        $this->assertSame(2, $rework->sequence);
        $this->assertDatabaseCount('service_deliveries', 0);
    }

    public function test_quality_validation_and_viewer_authorization_are_enforced(): void
    {
        [$organization, $actor, $order] = $this->completedOrder('validation');
        $viewer = $this->user($organization, UserRole::Viewer);
        $manager = app(ServiceCompletionManager::class);

        $this->assertDomainFailure(
            fn () => $manager->inspect(
                $this->approvedInspection(
                    $order,
                    'service:completion:quality:viewer'
                ),
                $viewer
            )
        );
        $this->assertDomainFailure(
            fn () => $manager->inspect(
                new ServiceQualityInspectionData(
                    serviceOrderId: $order->id,
                    checks: [
                        new ServiceQualityCheckData('power', 'Encendido', true),
                        new ServiceQualityCheckData('power', 'Carga', true),
                    ],
                    conditionNotes: 'Sin daños nuevos.',
                    accessoriesSnapshot: 'Equipo sin accesorios.',
                    idempotencyKey: 'service:completion:quality:duplicate'
                ),
                $actor
            )
        );
        $this->assertDomainFailure(
            fn () => $manager->inspect(
                new ServiceQualityInspectionData(
                    serviceOrderId: $order->id,
                    checks: [
                        new ServiceQualityCheckData('power', 'Encendido', false),
                    ],
                    conditionNotes: 'Sin daños nuevos.',
                    accessoriesSnapshot: 'Equipo sin accesorios.',
                    idempotencyKey: 'service:completion:quality:no-reason'
                ),
                $actor
            )
        );
        $this->assertDatabaseCount('service_quality_inspections', 0);
    }

    public function test_delivery_validates_conformity_recipient_and_latest_quality(): void
    {
        [$organization, $actor, $order, , , , $customer] =
            $this->completedOrder('delivery-guards');
        $manager = app(ServiceCompletionManager::class);
        $inspection = $manager->inspect(
            $this->approvedInspection(
                $order,
                'service:completion:quality:delivery-guards'
            ),
            $actor
        );

        $this->assertDomainFailure(
            fn () => $manager->deliver(
                new ServiceDeliveryData(
                    serviceOrderId: $order->id,
                    serviceQualityInspectionId: $inspection->id,
                    recipientName: $customer->name,
                    conditionNotes: 'Equipo verificado.',
                    accessoriesSnapshot: 'Equipo sin accesorios.',
                    customerConformity: false,
                    idempotencyKey: 'service:completion:delivery:no-notes'
                ),
                $actor
            )
        );
        $this->assertDomainFailure(
            fn () => $manager->deliver(
                new ServiceDeliveryData(
                    serviceOrderId: $order->id,
                    serviceQualityInspectionId: $inspection->id,
                    recipientName: $customer->name,
                    conditionNotes: 'Equipo verificado.',
                    accessoriesSnapshot: 'Equipo sin accesorios.',
                    customerConformity: true,
                    idempotencyKey: 'service:completion:delivery:old-date',
                    deliveredAt: CarbonImmutable::instance(
                        $inspection->inspected_at
                    )->subMinute()
                ),
                $actor
            )
        );

        $otherOrganization = Organization::query()->create([
            'name' => 'Taller ajeno',
            'slug' => 'taller-ajeno-delivery',
            'active' => true,
        ]);
        $foreignRecipient = $this->party(
            $otherOrganization,
            'Cliente de otro taller'
        );
        $this->assertDomainFailure(
            fn () => $manager->deliver(
                new ServiceDeliveryData(
                    serviceOrderId: $order->id,
                    serviceQualityInspectionId: $inspection->id,
                    recipientName: $foreignRecipient->name,
                    conditionNotes: 'Equipo verificado.',
                    accessoriesSnapshot: 'Equipo sin accesorios.',
                    customerConformity: true,
                    idempotencyKey: 'service:completion:delivery:foreign',
                    recipientBusinessPartyId: $foreignRecipient->id
                ),
                $actor
            )
        );

        $this->assertSame(
            ServiceOrderStatus::ReadyForDelivery,
            $order->fresh()->status
        );
        $this->assertSame($organization->id, $inspection->organization_id);
        $this->assertDatabaseCount('service_deliveries', 0);
    }

    public function test_database_guards_reject_status_and_evidence_tampering(): void
    {
        [, $actor, $order] = $this->completedOrder('database');
        $manager = app(ServiceCompletionManager::class);

        $this->assertQueryRejected(
            fn () => DB::table('service_orders')
                ->where('id', $order->id)
                ->update([
                    'status' => ServiceOrderStatus::ReadyForDelivery->value,
                ])
        );
        $this->assertQueryRejected(
            fn () => DB::table('service_quality_inspections')->insert([
                'organization_id' => $order->organization_id,
                'service_order_id' => $order->id,
                'revision' => 1,
                'outcome' => ServiceQualityOutcome::ReworkRequired->value,
                'check_count' => 2,
                'failed_check_count' => 1,
                'checks' => json_encode([
                    [
                        'code' => 'power',
                        'label' => 'Encendido',
                        'passed' => true,
                        'notes' => null,
                    ],
                    [
                        'code' => 'display',
                        'label' => 'Pantalla',
                        'passed' => true,
                        'notes' => null,
                    ],
                ], JSON_THROW_ON_ERROR),
                'condition_notes' => 'Intento directo.',
                'accessories_snapshot' => 'Sin accesorios.',
                'rework_reason' => 'Conteo falso.',
                'inspected_by_user_id' => $actor->id,
                'inspected_at' => now(),
                'idempotency_key' => 'service:completion:quality:db-invalid',
                'fingerprint' => hash('sha256', 'invalid'),
                'created_at' => now(),
                'updated_at' => now(),
            ])
        );

        $inspection = $manager->inspect(
            $this->approvedInspection(
                $order,
                'service:completion:quality:database'
            ),
            $actor
        );
        $this->assertQueryRejected(
            fn () => DB::table('service_quality_inspections')
                ->where('id', $inspection->id)
                ->update(['notes' => 'Alteración'])
        );
        $this->assertQueryRejected(
            fn () => DB::table('service_quality_inspections')
                ->where('id', $inspection->id)
                ->delete()
        );

        $delivery = $manager->deliver(
            new ServiceDeliveryData(
                serviceOrderId: $order->id,
                serviceQualityInspectionId: $inspection->id,
                recipientName: 'Cliente database',
                conditionNotes: 'Equipo verificado.',
                accessoriesSnapshot: 'Equipo sin accesorios.',
                customerConformity: true,
                idempotencyKey: 'service:completion:delivery:database'
            ),
            $actor
        );
        $warranty = $delivery->warranties->sole();

        $this->assertQueryRejected(
            fn () => DB::table('service_deliveries')
                ->where('id', $delivery->id)
                ->update(['recipient_name' => 'Nombre alterado'])
        );
        $this->assertQueryRejected(
            fn () => DB::table('service_warranty_grants')
                ->where('id', $warranty->id)
                ->update(['warranty_days' => 180])
        );
        $this->assertQueryRejected(
            fn () => DB::table('service_warranty_grants')
                ->where('id', $warranty->id)
                ->delete()
        );
        $this->assertDatabaseCount('service_deliveries', 1);
        $this->assertDatabaseCount('service_warranty_grants', 1);
    }

    /**
     * @return array{
     *     Organization,
     *     User,
     *     ServiceOrder,
     *     ServiceQuoteOption,
     *     ServiceWorkItem,
     *     ServiceWorkReport,
     *     BusinessParty
     * }
     */
    private function completedOrder(string $suffix): array
    {
        $organization = Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();
        $actor = $this->user($organization, UserRole::Operator);
        $customer = $this->party($organization, 'Cliente '.$suffix);
        $location = InventoryLocation::query()
            ->forOrganization($organization->id)
            ->orderBy('id')
            ->firstOrFail();
        $order = app(ServiceOrderIntakeManager::class)->create(
            new ServiceOrderIntakeData(
                assetType: ServiceAssetType::MobilePhone,
                brandName: 'Motorola',
                modelName: 'E22i',
                identifiers: [new ServiceAssetIdentifierData(
                    ServiceIdentifierType::Imei,
                    '359997'.str_pad(
                        (string) (abs(crc32($suffix)) % 1000000000),
                        9,
                        '0',
                        STR_PAD_LEFT
                    )
                )],
                intakeLocationId: $location->id,
                customerReportedIssue: 'Pantalla rota y fallas de software.',
                idempotencyKey: 'service:completion:intake:'.$suffix,
                customerBusinessPartyId: $customer->id,
                intakeObservations: 'Pantalla anterior no original.',
                receivedAccessories: 'Equipo sin accesorios.'
            ),
            $actor
        );
        $assessment = app(ServiceAssessmentManager::class);
        $assessment->recordDiagnostic(
            new ServiceDiagnosticData(
                serviceOrderId: $order->id,
                summary: 'Módulo dañado y software inestable.',
                recommendation: 'Reemplazar módulo y sanear software.',
                findings: [new ServiceDiagnosticFindingData(
                    ServiceFindingSeverity::Critical,
                    'Pantalla',
                    'El módulo no entrega imagen correctamente.'
                )],
                idempotencyKey: 'service:completion:diagnostic:'.$suffix
            ),
            $actor
        );
        $quote = $assessment->issueQuote(
            new ServiceQuoteData(
                serviceOrderId: $order->id,
                options: [new ServiceQuoteOptionData(
                    label: 'Reparación integral',
                    lines: [new ServiceQuoteLineData(
                        ServiceQuoteLineType::Labor,
                        'Cambio de módulo y saneamiento de software',
                        '1',
                        4500000
                    )],
                    recommended: true
                )],
                idempotencyKey: 'service:completion:quote:'.$suffix
            ),
            $actor
        );
        $option = $quote->options->sole();
        $assessment->recordDecision(
            new ServiceQuoteDecisionData(
                serviceQuoteId: $quote->id,
                decision: ServiceQuoteDecisionType::Approved,
                customerName: $customer->name,
                channel: 'Presencial',
                idempotencyKey: 'service:completion:decision:'.$suffix,
                serviceQuoteOptionId: $option->id
            ),
            $actor
        );
        $workManager = app(ServiceWorkManager::class);
        $work = $workManager->plan(
            new ServiceWorkItemData(
                serviceOrderId: $order->id,
                serviceQuoteOptionId: $option->id,
                title: 'Reparación integral del equipo',
                description: 'Cambio de módulo y limpieza de software propia.',
                executionMode: ServiceWorkExecutionMode::Internal,
                idempotencyKey: 'service:completion:work:'.$suffix,
                assignedUserId: $actor->id
            ),
            $actor
        );
        $workManager->startInternal(
            $work->id,
            'service:completion:start:'.$suffix,
            $actor
        );
        $report = $workManager->report(
            new ServiceWorkReportData(
                serviceWorkItemId: $work->id,
                outcome: ServiceWorkOutcome::Completed,
                resultSummary: 'Equipo reparado y estable.',
                workPerformed: 'Módulo instalado y software saneado.',
                idempotencyKey: 'service:completion:report:'.$suffix,
                warrantyDays: 90,
                warrantyTerms:
                    'Garantía sobre mano de obra y componentes instalados.'
            ),
            $actor
        );

        $this->assertSame(
            ServiceOrderStatus::QualityControl,
            $order->fresh()->status
        );

        return [
            $organization,
            $actor,
            $order,
            $option,
            $work,
            $report,
            $customer,
        ];
    }

    private function approvedInspection(
        ServiceOrder $order,
        string $key
    ): ServiceQualityInspectionData {
        return new ServiceQualityInspectionData(
            serviceOrderId: $order->id,
            checks: [
                new ServiceQualityCheckData(
                    'power',
                    'Encendido y estabilidad',
                    true
                ),
                new ServiceQualityCheckData(
                    'display',
                    'Imagen y táctil',
                    true
                ),
                new ServiceQualityCheckData(
                    'software',
                    'Uso sin publicidad intrusiva',
                    true
                ),
            ],
            conditionNotes: 'Equipo funcional, sin daños nuevos.',
            accessoriesSnapshot: 'Equipo sin accesorios.',
            idempotencyKey: $key,
            notes: 'Prueba final completa.'
        );
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
                ['role' => $role, 'active' => true]
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
