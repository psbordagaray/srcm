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
use App\Domain\Service\ServicePartManager;
use App\Domain\Service\ServiceQualityCheckData;
use App\Domain\Service\ServiceQualityInspectionData;
use App\Domain\Service\ServiceQuoteData;
use App\Domain\Service\ServiceQuoteDecisionData;
use App\Domain\Service\ServiceQuoteLineData;
use App\Domain\Service\ServiceQuoteOptionData;
use App\Domain\Service\ServiceWarrantyClaimData;
use App\Domain\Service\ServiceWarrantyClaimManager;
use App\Domain\Service\ServiceWarrantyClaimResolutionData;
use App\Domain\Service\ServiceWarrantyClaimReturnData;
use App\Domain\Service\ServiceWarrantyPartRequirementData;
use App\Domain\Service\ServiceWarrantyWorkItemData;
use App\Domain\Service\ServiceWorkCustodyData;
use App\Domain\Service\ServiceWorkItemData;
use App\Domain\Service\ServiceWorkManager;
use App\Domain\Service\ServiceWorkReportData;
use App\Enums\InventoryCondition;
use App\Enums\ServiceAssetType;
use App\Enums\ServiceCustodyEventType;
use App\Enums\ServiceFindingSeverity;
use App\Enums\ServiceIdentifierType;
use App\Enums\ServiceOrderStatus;
use App\Enums\ServicePartSource;
use App\Enums\ServiceQuoteDecisionType;
use App\Enums\ServiceQuoteLineType;
use App\Enums\ServiceWarrantyClaimOutcome;
use App\Enums\ServiceWarrantyClaimStatus;
use App\Enums\ServiceWarrantyTemporalStatus;
use App\Enums\ServiceWorkExecutionMode;
use App\Enums\ServiceWorkOutcome;
use App\Enums\UserRole;
use App\Models\BusinessParty;
use App\Models\CatalogProduct;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductCategory;
use App\Models\ServiceOrder;
use App\Models\ServiceWarrantyClaim;
use App\Models\ServiceWarrantyGrant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ServiceWarrantyClaimFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_schema_enums_and_permissions_are_explicit(): void
    {
        $this->assertTrue(Schema::hasColumns('service_warranty_claims', [
            'organization_id',
            'service_warranty_grant_id',
            'open_warranty_grant_id',
            'original_service_order_id',
            'original_service_delivery_id',
            'corrective_service_order_id',
            'warranty_status_at_claim',
            'status',
            'closed_at',
            'idempotency_key',
            'fingerprint',
        ]));
        $this->assertTrue(Schema::hasTable(
            'service_warranty_claim_status_histories'
        ));
        $this->assertTrue(Schema::hasTable(
            'service_warranty_claim_resolutions'
        ));
        $this->assertTrue(Schema::hasTable(
            'service_warranty_claim_returns'
        ));
        $this->assertTrue(Schema::hasColumn(
            'service_work_items',
            'service_warranty_claim_resolution_id'
        ));
        $this->assertTrue(Schema::hasColumn(
            'service_part_requirements',
            'service_warranty_claim_resolution_id'
        ));

        $this->assertTrue(
            UserRole::Operator->canRegisterServiceWarrantyClaims()
        );
        $this->assertFalse(
            UserRole::Viewer->canRegisterServiceWarrantyClaims()
        );
        $this->assertTrue(
            UserRole::Admin->canResolveServiceWarrantyClaims()
        );
        $this->assertFalse(
            UserRole::Operator->canResolveServiceWarrantyClaims()
        );
        $this->assertTrue(
            UserRole::Operator->canReturnServiceWarrantyClaims()
        );
        $this->assertFalse(
            UserRole::Viewer->canReturnServiceWarrantyClaims()
        );

        $this->assertTrue(
            ServiceWarrantyClaimOutcome::Accepted
                ->authorizesCorrectiveWork()
        );
        $this->assertTrue(
            ServiceWarrantyClaimOutcome::PartiallyAccepted
                ->authorizesCorrectiveWork()
        );
        $this->assertFalse(
            ServiceWarrantyClaimOutcome::Rejected
                ->authorizesCorrectiveWork()
        );
    }

    public function test_claim_reuses_asset_and_preserves_original_facts(): void
    {
        $fixture = $this->deliveredWarranty('register');
        $manager = app(ServiceWarrantyClaimManager::class);
        $data = $this->claimData($fixture, 'register');
        $beforeOrders = ServiceOrder::query()->count();
        $originalStatus = $fixture['order']->status;
        $originalDeliveryId = $fixture['delivery']->id;
        $originalWarrantyFingerprint = $fixture['warranty']->fingerprint;

        $claim = $manager->register($data, $fixture['operator']);
        $retry = $manager->register($data, $fixture['operator']);

        $this->assertSame($claim->id, $retry->id);
        $this->assertSame(
            ServiceWarrantyClaimStatus::PendingReview,
            $claim->status
        );
        $this->assertSame(
            ServiceWarrantyTemporalStatus::Active,
            $claim->warranty_status_at_claim
        );
        $this->assertSame(
            $fixture['warranty']->id,
            $claim->open_warranty_grant_id
        );
        $this->assertSame($beforeOrders + 1, ServiceOrder::query()->count());
        $this->assertNotSame(
            $fixture['order']->id,
            $claim->corrective_service_order_id
        );
        $this->assertSame(
            $fixture['order']->service_asset_id,
            $claim->correctiveOrder->service_asset_id
        );
        $this->assertSame(
            ServiceOrderStatus::Received,
            $claim->correctiveOrder->status
        );
        $this->assertSame(
            $originalStatus,
            $fixture['order']->fresh()->status
        );
        $this->assertSame(
            $originalDeliveryId,
            $fixture['order']->fresh()->delivery->id
        );
        $this->assertSame(
            $originalWarrantyFingerprint,
            $fixture['warranty']->fresh()->fingerprint
        );
        $this->assertCount(1, $claim->statusHistory);
        $this->assertSame(
            ServiceCustodyEventType::Received,
            $claim->correctiveOrder->custodyEvents()->sole()->event_type
        );

        $this->assertDomainFailure(
            fn () => $manager->register(
                $this->claimData($fixture, 'second-open'),
                $fixture['operator']
            )
        );
        $this->assertDomainFailure(
            fn () => $manager->register(
                new ServiceWarrantyClaimData(
                    serviceWarrantyGrantId: $fixture['warranty']->id,
                    intakeLocationId: $fixture['location']->id,
                    claimantName: $fixture['customer']->name,
                    reportedIssue: 'Otra falla con la misma clave.',
                    reentryConditionNotes: 'Sin daños nuevos.',
                    accessoriesSnapshot: 'Equipo sin accesorios.',
                    channel: 'Presencial',
                    claimedAt: CarbonImmutable::now(),
                    idempotencyKey: 'service:warranty:claim:register',
                    claimantBusinessPartyId: $fixture['customer']->id
                ),
                $fixture['operator']
            )
        );

        $foreign = $this->newOrganization('Garantías ajenas');
        $foreignActor = $this->user($foreign, UserRole::Operator);
        $this->assertDomainFailure(
            fn () => $manager->register(
                $this->claimData($fixture, 'foreign'),
                $foreignActor
            )
        );

        $this->assertSame($beforeOrders + 1, ServiceOrder::query()->count());
        $this->assertQueryRejected(
            fn () => DB::table('service_warranty_claims')
                ->where('id', $claim->id)
                ->update(['reported_issue' => 'Intento de reescritura'])
        );
        $this->assertQueryRejected(
            fn () => DB::table('service_warranty_claims')
                ->where('id', $claim->id)
                ->delete()
        );
    }

    public function test_accepted_claim_runs_corrective_work_and_closes_on_delivery(): void
    {
        $fixture = $this->deliveredWarranty('accepted');
        $claimManager = app(ServiceWarrantyClaimManager::class);
        $claim = $claimManager->register(
            $this->claimData($fixture, 'accepted'),
            $fixture['operator']
        );

        $this->assertDomainFailure(
            fn () => $claimManager->resolve(
                $this->acceptedResolutionData($claim, 'operator-denied'),
                $fixture['operator']
            )
        );

        $resolutionData = $this->acceptedResolutionData($claim, 'accepted');
        $resolution = $claimManager->resolve(
            $resolutionData,
            $fixture['admin']
        );
        $resolutionRetry = $claimManager->resolve(
            $resolutionData,
            $fixture['admin']
        );
        $correctiveOrder = $claim->correctiveOrder->fresh();

        $this->assertSame($resolution->id, $resolutionRetry->id);
        $this->assertSame(
            ServiceWarrantyClaimOutcome::Accepted,
            $resolution->outcome
        );
        $this->assertSame(
            ServiceWarrantyClaimStatus::InCorrectiveWork,
            $claim->fresh()->status
        );
        $this->assertSame(
            ServiceOrderStatus::InProgress,
            $correctiveOrder->status
        );

        $workManager = app(ServiceWorkManager::class);
        $workData = new ServiceWarrantyWorkItemData(
            serviceOrderId: $correctiveOrder->id,
            serviceWarrantyClaimResolutionId: $resolution->id,
            title: 'Corrección cubierta por garantía',
            description: 'Reemplazo y ajuste del componente reclamado.',
            executionMode: ServiceWorkExecutionMode::Internal,
            idempotencyKey: 'service:warranty:work:accepted',
            assignedUserId: $fixture['operator']->id
        );
        $work = $workManager->planWarranty(
            $workData,
            $fixture['operator']
        );
        $workRetry = $workManager->planWarranty(
            $workData,
            $fixture['operator']
        );

        $this->assertSame($work->id, $workRetry->id);
        $this->assertNull($work->service_quote_option_id);
        $this->assertSame(
            $resolution->id,
            $work->service_warranty_claim_resolution_id
        );

        $workManager->startInternal(
            $work->id,
            'service:warranty:start:accepted',
            $fixture['operator']
        );
        $report = $workManager->report(
            new ServiceWorkReportData(
                serviceWorkItemId: $work->id,
                outcome: ServiceWorkOutcome::Completed,
                resultSummary: 'Falla de garantía corregida.',
                workPerformed: 'Componente ajustado y pruebas completas.',
                idempotencyKey: 'service:warranty:report:accepted',
                warrantyDays: 30,
                warrantyTerms: 'Garantía de 30 días sobre la corrección.'
            ),
            $fixture['operator']
        );

        $this->assertSame(
            ServiceOrderStatus::QualityControl,
            $correctiveOrder->fresh()->status
        );

        $completion = app(ServiceCompletionManager::class);
        $inspection = $completion->inspect(
            $this->approvedInspection(
                $correctiveOrder,
                'service:warranty:quality:accepted'
            ),
            $fixture['operator']
        );
        $delivery = $completion->deliver(
            new ServiceDeliveryData(
                serviceOrderId: $correctiveOrder->id,
                serviceQualityInspectionId: $inspection->id,
                recipientName: $fixture['customer']->name,
                conditionNotes: 'Equipo probado y sin daños nuevos.',
                accessoriesSnapshot: 'Equipo sin accesorios.',
                customerConformity: true,
                idempotencyKey: 'service:warranty:delivery:accepted',
                recipientBusinessPartyId: $fixture['customer']->id,
                deliveredAt: $inspection->inspected_at
            ),
            $fixture['operator']
        );

        $this->assertSame(
            ServiceOrderStatus::Delivered,
            $correctiveOrder->fresh()->status
        );
        $this->assertSame(
            ServiceWarrantyClaimStatus::Closed,
            $claim->fresh()->status
        );
        $this->assertNull($claim->fresh()->open_warranty_grant_id);
        $this->assertNotNull($claim->fresh()->closed_at);
        $this->assertSame(
            ServiceOrderStatus::Delivered,
            $fixture['order']->fresh()->status
        );
        $this->assertDatabaseHas('service_warranty_grants', [
            'organization_id' => $fixture['organization']->id,
            'service_delivery_id' => $delivery->id,
            'service_work_report_id' => $report->id,
            'warranty_days' => 30,
        ]);
        $this->assertFalse(
            $correctiveOrder->fresh()->canRequestCancellation()
        );
    }

    public function test_partial_acceptance_supports_warranty_parts_and_xor_guards(): void
    {
        $fixture = $this->deliveredWarranty('partial');
        $claimManager = app(ServiceWarrantyClaimManager::class);
        $claim = $claimManager->register(
            $this->claimData($fixture, 'partial'),
            $fixture['operator']
        );
        $resolution = $claimManager->resolve(
            new ServiceWarrantyClaimResolutionData(
                serviceWarrantyClaimId: $claim->id,
                outcome: ServiceWarrantyClaimOutcome::PartiallyAccepted,
                technicalBasis: 'La falla del módulo está cubierta; el golpe exterior no.',
                idempotencyKey: 'service:warranty:resolution:partial',
                coveredScope: 'Módulo instalado y mano de obra.',
                excludedScope: 'Daño cosmético por impacto posterior.'
            ),
            $fixture['admin']
        );
        $work = app(ServiceWorkManager::class)->planWarranty(
            new ServiceWarrantyWorkItemData(
                serviceOrderId: $claim->corrective_service_order_id,
                serviceWarrantyClaimResolutionId: $resolution->id,
                title: 'Corrección parcial de garantía',
                description: 'Reemplazo del módulo dentro de cobertura.',
                executionMode: ServiceWorkExecutionMode::Internal,
                idempotencyKey: 'service:warranty:work:partial',
                assignedUserId: $fixture['operator']->id
            ),
            $fixture['operator']
        );
        $product = $this->product(
            'Módulo correctivo de garantía',
            'WARRANTY-PARTIAL-1'
        );
        $partManager = app(ServicePartManager::class);
        $requirementData = new ServiceWarrantyPartRequirementData(
            serviceWorkItemId: $work->id,
            serviceWarrantyClaimResolutionId: $resolution->id,
            catalogProductId: $product->id,
            condition: InventoryCondition::New,
            source: ServicePartSource::Stock,
            requiredQuantity: '1',
            idempotencyKey: 'service:warranty:part:partial'
        );
        $requirement = $partManager->planWarranty(
            $requirementData,
            $fixture['operator']
        );
        $requirementRetry = $partManager->planWarranty(
            $requirementData,
            $fixture['operator']
        );

        $this->assertSame($requirement->id, $requirementRetry->id);
        $this->assertNull($requirement->service_quote_line_id);
        $this->assertSame(
            $resolution->id,
            $requirement->service_warranty_claim_resolution_id
        );
        $this->assertSame(
            ServiceWarrantyClaimStatus::InCorrectiveWork,
            $claim->fresh()->status
        );

        $this->assertQueryRejected(
            fn () => DB::table('service_work_items')
                ->where('id', $work->id)
                ->update([
                    'service_quote_option_id' => $fixture['option']->id,
                ])
        );
        $this->assertQueryRejected(
            fn () => DB::table('service_work_items')
                ->where('id', $work->id)
                ->update([
                    'service_warranty_claim_resolution_id' => null,
                ])
        );
        $this->assertQueryRejected(
            fn () => DB::table('service_part_requirements')
                ->where('id', $requirement->id)
                ->update([
                    'service_quote_line_id' => $fixture['partLineId'],
                ])
        );
        $this->assertQueryRejected(
            fn () => DB::table('service_part_requirements')
                ->where('id', $requirement->id)
                ->update([
                    'service_warranty_claim_resolution_id' => null,
                ])
        );
    }

    public function test_rejected_claim_returns_asset_without_fake_delivery(): void
    {
        $fixture = $this->deliveredWarranty('rejected');
        $claimManager = app(ServiceWarrantyClaimManager::class);
        $claim = $claimManager->register(
            $this->claimData($fixture, 'rejected'),
            $fixture['operator']
        );
        $resolution = $claimManager->resolve(
            new ServiceWarrantyClaimResolutionData(
                serviceWarrantyClaimId: $claim->id,
                outcome: ServiceWarrantyClaimOutcome::Rejected,
                technicalBasis: 'El daño corresponde a humedad posterior a la entrega.',
                idempotencyKey: 'service:warranty:resolution:rejected',
                excludedScope: 'Daño por líquido no cubierto.'
            ),
            $fixture['admin']
        );

        $this->assertSame(
            ServiceWarrantyClaimStatus::ReadyForReturn,
            $claim->fresh()->status
        );
        $this->assertSame(
            ServiceOrderStatus::ReadyForReturn,
            $claim->correctiveOrder->fresh()->status
        );

        $returnData = new ServiceWarrantyClaimReturnData(
            serviceWarrantyClaimId: $claim->id,
            recipientName: $fixture['customer']->name,
            conditionNotes: 'Se devuelve en la misma condición de reingreso.',
            accessoriesSnapshot: 'Equipo sin accesorios.',
            idempotencyKey: 'service:warranty:return:rejected',
            recipientBusinessPartyId: $fixture['customer']->id,
            recipientDocument: 'DNI 30111222'
        );
        $return = $claimManager->returnAsset(
            $returnData,
            $fixture['operator']
        );
        $retry = $claimManager->returnAsset(
            $returnData,
            $fixture['operator']
        );

        $this->assertSame($return->id, $retry->id);
        $this->assertSame($resolution->id, $return->resolution->id);
        $this->assertSame(
            ServiceCustodyEventType::WarrantyReturned,
            $return->custodyEvent->event_type
        );
        $this->assertSame(
            ServiceWarrantyClaimStatus::Closed,
            $claim->fresh()->status
        );
        $this->assertSame(
            ServiceOrderStatus::Cancelled,
            $claim->correctiveOrder->fresh()->status
        );
        $this->assertNull($claim->correctiveOrder->fresh()->delivery);
        $this->assertSame(
            ServiceOrderStatus::Delivered,
            $fixture['order']->fresh()->status
        );
    }

    public function test_expired_claim_requires_admin_exception_reason(): void
    {
        $base = CarbonImmutable::now()->subDays(120)->startOfSecond();
        CarbonImmutable::setTestNow($base);
        $fixture = $this->deliveredWarranty('expired', 30);
        CarbonImmutable::setTestNow($base->addDays(45));
        $claimManager = app(ServiceWarrantyClaimManager::class);
        $claim = $claimManager->register(
            $this->claimData(
                $fixture,
                'expired',
                CarbonImmutable::now()->subDay()
            ),
            $fixture['operator']
        );

        $this->assertSame(
            ServiceWarrantyTemporalStatus::Expired,
            $claim->warranty_status_at_claim
        );
        $this->assertDomainFailure(
            fn () => $claimManager->resolve(
                new ServiceWarrantyClaimResolutionData(
                    serviceWarrantyClaimId: $claim->id,
                    outcome: ServiceWarrantyClaimOutcome::Accepted,
                    technicalBasis: 'Se autoriza excepcionalmente por antecedente técnico.',
                    idempotencyKey: 'service:warranty:resolution:expired:missing',
                    coveredScope: 'Corrección completa.'
                ),
                $fixture['admin']
            )
        );

        $resolution = $claimManager->resolve(
            new ServiceWarrantyClaimResolutionData(
                serviceWarrantyClaimId: $claim->id,
                outcome: ServiceWarrantyClaimOutcome::Accepted,
                technicalBasis: 'Se autoriza excepcionalmente por antecedente técnico.',
                idempotencyKey: 'service:warranty:resolution:expired:approved',
                coveredScope: 'Corrección completa.',
                exceptionReason: 'Se acepta por recurrencia documentada de la falla original.'
            ),
            $fixture['admin']
        );

        $this->assertTrue($resolution->administrative_exception);
        $this->assertSame(
            ServiceWarrantyTemporalStatus::Expired,
            $resolution->warranty_status_at_resolution
        );
        $this->assertSame(
            ServiceWarrantyClaimStatus::InCorrectiveWork,
            $claim->fresh()->status
        );
    }

    public function test_external_corrective_work_preserves_custody_trace(): void
    {
        $fixture = $this->deliveredWarranty('external');
        $claimManager = app(ServiceWarrantyClaimManager::class);
        $claim = $claimManager->register(
            $this->claimData($fixture, 'external'),
            $fixture['operator']
        );
        $resolution = $claimManager->resolve(
            $this->acceptedResolutionData($claim, 'external'),
            $fixture['admin']
        );
        $provider = $this->party(
            $fixture['organization'],
            'Especialista de garantía'
        );
        $workManager = app(ServiceWorkManager::class);
        $work = $workManager->planWarranty(
            new ServiceWarrantyWorkItemData(
                serviceOrderId: $claim->corrective_service_order_id,
                serviceWarrantyClaimResolutionId: $resolution->id,
                title: 'Corrección externa de garantía',
                description: 'Intervención del especialista autorizado.',
                executionMode: ServiceWorkExecutionMode::External,
                idempotencyKey: 'service:warranty:work:external',
                providerBusinessPartyId: $provider->id
            ),
            $fixture['operator']
        );
        $dispatch = $workManager->dispatchExternal(
            new ServiceWorkCustodyData(
                serviceWorkItemId: $work->id,
                conditionNotes: 'Equipo sin daños nuevos.',
                accessoriesSnapshot: 'Equipo sin accesorios.',
                idempotencyKey: 'service:warranty:dispatch:external'
            ),
            $fixture['operator']
        );

        $this->assertSame(
            ServiceOrderStatus::WithExternalProvider,
            $claim->correctiveOrder->fresh()->status
        );
        $this->assertSame(
            ServiceCustodyEventType::Transferred,
            $dispatch->custodyEvent->event_type
        );

        $returned = $workManager->returnExternal(
            new ServiceWorkCustodyData(
                serviceWorkItemId: $work->id,
                conditionNotes: 'Equipo corregido y probado.',
                accessoriesSnapshot: 'Equipo sin accesorios.',
                idempotencyKey: 'service:warranty:return:external'
            ),
            $fixture['operator']
        );
        $workManager->report(
            new ServiceWorkReportData(
                serviceWorkItemId: $work->id,
                outcome: ServiceWorkOutcome::Completed,
                resultSummary: 'Corrección externa completada.',
                workPerformed: 'Reparación por especialista autorizado.',
                idempotencyKey: 'service:warranty:report:external',
                warrantyDays: 15,
                warrantyTerms: 'Garantía sobre la corrección externa.'
            ),
            $fixture['operator']
        );

        $this->assertSame(
            ServiceCustodyEventType::Returned,
            $returned->custodyEvent->event_type
        );
        $this->assertSame(
            ServiceOrderStatus::QualityControl,
            $claim->correctiveOrder->fresh()->status
        );
        $this->assertCount(2, $work->fresh()->custodyLinks);
    }

    /**
     * @return array{
     *   organization: Organization,
     *   operator: User,
     *   admin: User,
     *   order: ServiceOrder,
     *   option: mixed,
     *   partLineId: int,
     *   customer: BusinessParty,
     *   location: InventoryLocation,
     *   delivery: mixed,
     *   warranty: ServiceWarrantyGrant
     * }
     */
    private function deliveredWarranty(
        string $suffix,
        int $warrantyDays = 90
    ): array {
        $organization = Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();
        $operator = $this->user($organization, UserRole::Operator);
        $admin = $this->user($organization, UserRole::Admin);
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
                idempotencyKey: 'service:warranty:intake:'.$suffix,
                customerBusinessPartyId: $customer->id,
                intakeObservations: 'Pantalla anterior no original.',
                receivedAccessories: 'Equipo sin accesorios.'
            ),
            $operator
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
                idempotencyKey: 'service:warranty:diagnostic:'.$suffix
            ),
            $operator
        );
        $quote = $assessment->issueQuote(
            new ServiceQuoteData(
                serviceOrderId: $order->id,
                options: [new ServiceQuoteOptionData(
                    label: 'Reparación integral',
                    lines: [
                        new ServiceQuoteLineData(
                            ServiceQuoteLineType::Labor,
                            'Cambio de módulo y saneamiento de software',
                            '1',
                            4500000
                        ),
                        new ServiceQuoteLineData(
                            ServiceQuoteLineType::Part,
                            'Módulo de pantalla',
                            '1',
                            2500000
                        ),
                    ],
                    recommended: true
                )],
                idempotencyKey: 'service:warranty:quote:'.$suffix
            ),
            $operator
        );
        $option = $quote->options->sole();
        $partLine = $option->lines->first(
            fn ($line) => $line->line_type === ServiceQuoteLineType::Part
        );
        $assessment->recordDecision(
            new ServiceQuoteDecisionData(
                serviceQuoteId: $quote->id,
                decision: ServiceQuoteDecisionType::Approved,
                customerName: $customer->name,
                channel: 'Presencial',
                idempotencyKey: 'service:warranty:decision:'.$suffix,
                serviceQuoteOptionId: $option->id
            ),
            $operator
        );
        $workManager = app(ServiceWorkManager::class);
        $work = $workManager->plan(
            new ServiceWorkItemData(
                serviceOrderId: $order->id,
                serviceQuoteOptionId: $option->id,
                title: 'Reparación integral del equipo',
                description: 'Cambio de módulo y limpieza de software.',
                executionMode: ServiceWorkExecutionMode::Internal,
                idempotencyKey: 'service:warranty:original-work:'.$suffix,
                assignedUserId: $operator->id
            ),
            $operator
        );
        $workManager->startInternal(
            $work->id,
            'service:warranty:original-start:'.$suffix,
            $operator
        );
        $report = $workManager->report(
            new ServiceWorkReportData(
                serviceWorkItemId: $work->id,
                outcome: ServiceWorkOutcome::Completed,
                resultSummary: 'Equipo reparado y estable.',
                workPerformed: 'Módulo instalado y software saneado.',
                idempotencyKey: 'service:warranty:original-report:'.$suffix,
                warrantyDays: $warrantyDays,
                warrantyTerms: 'Garantía sobre mano de obra y componentes instalados.'
            ),
            $operator
        );
        $completion = app(ServiceCompletionManager::class);
        $inspection = $completion->inspect(
            $this->approvedInspection(
                $order,
                'service:warranty:original-quality:'.$suffix
            ),
            $operator
        );
        $delivery = $completion->deliver(
            new ServiceDeliveryData(
                serviceOrderId: $order->id,
                serviceQualityInspectionId: $inspection->id,
                recipientName: $customer->name,
                conditionNotes: 'Equipo encendido, probado y sin daños nuevos.',
                accessoriesSnapshot: 'Equipo sin accesorios.',
                customerConformity: true,
                idempotencyKey: 'service:warranty:original-delivery:'.$suffix,
                recipientBusinessPartyId: $customer->id,
                deliveredAt: $inspection->inspected_at
            ),
            $operator
        );
        $warranty = ServiceWarrantyGrant::query()
            ->where('service_delivery_id', $delivery->id)
            ->where('service_work_report_id', $report->id)
            ->sole();

        return [
            'organization' => $organization,
            'operator' => $operator,
            'admin' => $admin,
            'order' => $order->fresh(),
            'option' => $option,
            'partLineId' => (int) $partLine->id,
            'customer' => $customer,
            'location' => $location,
            'delivery' => $delivery,
            'warranty' => $warranty,
        ];
    }

    /** @param array<string, mixed> $fixture */
    private function claimData(
        array $fixture,
        string $suffix,
        ?CarbonImmutable $claimedAt = null
    ): ServiceWarrantyClaimData {
        return new ServiceWarrantyClaimData(
            serviceWarrantyGrantId: $fixture['warranty']->id,
            intakeLocationId: $fixture['location']->id,
            claimantName: $fixture['customer']->name,
            reportedIssue: 'La falla original volvió a presentarse.',
            reentryConditionNotes: 'Sin golpes ni daños nuevos.',
            accessoriesSnapshot: 'Equipo sin accesorios.',
            channel: 'Presencial',
            claimedAt: $claimedAt ?? CarbonImmutable::now(),
            idempotencyKey: 'service:warranty:claim:'.$suffix,
            claimantBusinessPartyId: $fixture['customer']->id,
            customerReference: 'REC-'.$suffix
        );
    }

    private function acceptedResolutionData(
        ServiceWarrantyClaim $claim,
        string $suffix
    ): ServiceWarrantyClaimResolutionData {
        return new ServiceWarrantyClaimResolutionData(
            serviceWarrantyClaimId: $claim->id,
            outcome: ServiceWarrantyClaimOutcome::Accepted,
            technicalBasis: 'La falla coincide con el trabajo y componente garantizados.',
            idempotencyKey: 'service:warranty:resolution:'.$suffix,
            coveredScope: 'Corrección integral de la falla reclamada.'
        );
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
                    'Uso estable',
                    true
                ),
            ],
            conditionNotes: 'Equipo funcional, sin daños nuevos.',
            accessoriesSnapshot: 'Equipo sin accesorios.',
            idempotencyKey: $key,
            notes: 'Prueba final completa.'
        );
    }

    private function product(string $name, string $sku): CatalogProduct
    {
        $category = ProductCategory::withoutEvents(
            fn () => ProductCategory::query()->firstOrCreate(
                ['slug' => 'service-warranty-parts'],
                ['name' => 'Repuestos de garantía', 'active' => true]
            )
        );

        return CatalogProduct::withoutEvents(
            fn () => CatalogProduct::query()->create([
                'product_category_id' => $category->id,
                'sku' => $sku,
                'name' => $name,
                'active' => true,
            ])->refresh()
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

    private function newOrganization(string $name): Organization
    {
        return Organization::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'active' => true,
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
