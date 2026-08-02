<?php

namespace Tests\Feature\Service;

use App\Domain\Service\ServiceAssessmentManager;
use App\Domain\Service\ServiceAssetIdentifierData;
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
use App\Domain\Service\ServiceWorkReportData;
use App\Enums\ServiceAssetType;
use App\Enums\ServiceFindingSeverity;
use App\Enums\ServiceIdentifierType;
use App\Enums\ServiceOrderStatus;
use App\Enums\ServiceQuoteDecisionType;
use App\Enums\ServiceQuoteLineType;
use App\Enums\ServiceWorkCustodyDirection;
use App\Enums\ServiceWorkExecutionMode;
use App\Enums\ServiceWorkOutcome;
use App\Enums\ServiceWorkStatus;
use App\Enums\UserRole;
use App\Models\BusinessParty;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ServiceOrder;
use App\Models\ServiceQuoteOption;
use App\Models\ServiceWorkItem;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ServiceWorkFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_schema_and_execution_permissions_are_explicit(): void
    {
        $this->assertTrue(Schema::hasColumns('service_work_items', [
            'organization_id',
            'service_order_id',
            'service_quote_option_id',
            'execution_mode',
            'provider_business_party_id',
            'assigned_user_id',
            'status',
            'idempotency_key',
        ]));
        $this->assertTrue(
            Schema::hasTable('service_work_status_histories')
        );
        $this->assertTrue(Schema::hasTable('service_work_custody_links'));
        $this->assertTrue(Schema::hasTable('service_work_reports'));

        $this->assertTrue(UserRole::Admin->canPlanServiceWork());
        $this->assertTrue(UserRole::Operator->canPlanServiceWork());
        $this->assertFalse(UserRole::Viewer->canPlanServiceWork());
        $this->assertTrue(UserRole::Admin->canExecuteServiceWork());
        $this->assertTrue(UserRole::Operator->canExecuteServiceWork());
        $this->assertFalse(UserRole::Viewer->canExecuteServiceWork());
        $this->assertTrue(
            UserRole::Admin->canTransferServiceCustody()
        );
        $this->assertTrue(
            UserRole::Operator->canTransferServiceCustody()
        );
        $this->assertFalse(
            UserRole::Viewer->canTransferServiceCustody()
        );
    }

    public function test_internal_work_records_author_result_and_warranty(): void
    {
        [$organization, $actor, $order, $option] = $this->approvedOrder();
        $manager = app(ServiceWorkManager::class);
        $data = new ServiceWorkItemData(
            serviceOrderId: $order->id,
            serviceQuoteOptionId: $option->id,
            title: 'Instalar SSD y migrar datos',
            description: 'Trabajo propio de SULU con respaldo verificado.',
            executionMode: ServiceWorkExecutionMode::Internal,
            idempotencyKey: 'service:work:internal:1',
            assignedUserId: $actor->id
        );

        $work = $manager->plan($data, $actor);
        $retry = $manager->plan($data, $actor);

        $this->assertSame($work->id, $retry->id);
        $this->assertSame($organization->id, $work->organization_id);
        $this->assertSame(ServiceWorkStatus::Planned, $work->status);
        $this->assertSame($actor->id, $work->assigned_user_id);
        $this->assertNull($work->provider_business_party_id);

        $started = $manager->startInternal(
            $work->id,
            'service:work:internal:start:1',
            $actor
        );
        $this->assertSame(ServiceWorkStatus::InProgress, $started->status);

        $reportData = new ServiceWorkReportData(
            serviceWorkItemId: $work->id,
            outcome: ServiceWorkOutcome::Completed,
            resultSummary: 'Notebook funcional y sensiblemente más rápida.',
            workPerformed: 'SSD instalado, sistema migrado y datos probados.',
            idempotencyKey: 'service:work:internal:report:1',
            warrantyDays: 90,
            warrantyTerms: 'Garantía sobre instalación y mano de obra.'
        );
        $report = $manager->report($reportData, $actor);
        $reportRetry = $manager->report($reportData, $actor);

        $this->assertSame($report->id, $reportRetry->id);
        $this->assertSame(ServiceWorkOutcome::Completed, $report->outcome);
        $this->assertSame(90, $report->warranty_days);
        $this->assertSame($actor->id, $report->recorded_by_user_id);
        $this->assertSame(
            ServiceWorkStatus::Completed,
            $work->fresh()->status
        );
        $this->assertSame(
            ServiceOrderStatus::QualityControl,
            $order->fresh()->status
        );
        $this->assertDatabaseCount('service_work_items', 1);
        $this->assertDatabaseCount('service_work_reports', 1);
        $this->assertDatabaseCount('service_work_status_histories', 3);
    }

    public function test_external_work_traces_dispatch_return_and_provider(): void
    {
        [$organization, $actor, $order, $option] = $this->approvedOrder();
        $provider = $this->party($organization, 'Horacio notebooks');
        $manager = app(ServiceWorkManager::class);
        $work = $manager->plan(
            new ServiceWorkItemData(
                serviceOrderId: $order->id,
                serviceQuoteOptionId: $option->id,
                title: 'Desarme y reparación especializada',
                description: 'Diagnóstico físico y reparación tercerizada.',
                executionMode: ServiceWorkExecutionMode::External,
                idempotencyKey: 'service:work:external:1',
                providerBusinessPartyId: $provider->id
            ),
            $actor
        );
        $dispatchData = new ServiceWorkCustodyData(
            serviceWorkItemId: $work->id,
            conditionNotes: 'Notebook cerrada, sin golpes nuevos.',
            accessoriesSnapshot: 'Notebook y cargador original.',
            idempotencyKey: 'service:work:external:dispatch:1'
        );

        $dispatch = $manager->dispatchExternal($dispatchData, $actor);
        $dispatchRetry = $manager->dispatchExternal($dispatchData, $actor);

        $this->assertSame($dispatch->id, $dispatchRetry->id);
        $this->assertSame(
            ServiceWorkCustodyDirection::Dispatch,
            $dispatch->direction
        );
        $this->assertSame(
            'external_provider',
            $dispatch->custodyEvent->to_holder_type
        );
        $this->assertSame(
            'Horacio notebooks',
            $dispatch->custodyEvent->to_holder_name
        );
        $this->assertSame(
            ServiceWorkStatus::WithProvider,
            $work->fresh()->status
        );
        $this->assertSame(
            ServiceOrderStatus::WithExternalProvider,
            $order->fresh()->status
        );

        $returnData = new ServiceWorkCustodyData(
            serviceWorkItemId: $work->id,
            conditionNotes: 'Retorna armada y encendiendo correctamente.',
            accessoriesSnapshot: 'Notebook y cargador original.',
            idempotencyKey: 'service:work:external:return:1'
        );
        $return = $manager->returnExternal($returnData, $actor);
        $returnRetry = $manager->returnExternal($returnData, $actor);

        $this->assertSame($return->id, $returnRetry->id);
        $this->assertSame(
            ServiceWorkCustodyDirection::Return,
            $return->direction
        );
        $this->assertSame(
            'organization',
            $return->custodyEvent->to_holder_type
        );
        $this->assertSame(
            ServiceWorkStatus::InProgress,
            $work->fresh()->status
        );
        $this->assertSame(
            ServiceOrderStatus::InProgress,
            $order->fresh()->status
        );
        $this->assertSame($provider->id, $work->provider_business_party_id);
        $this->assertDatabaseCount('service_work_custody_links', 2);
        $this->assertDatabaseCount('service_custody_events', 3);

        $report = $manager->report(
            new ServiceWorkReportData(
                serviceWorkItemId: $work->id,
                outcome: ServiceWorkOutcome::Completed,
                resultSummary: 'Reparación externa recibida y verificada.',
                workPerformed: 'Reparación de placa realizada por Horacio.',
                idempotencyKey: 'service:work:external:report:1',
                warrantyDays: 60,
                warrantyTerms: 'Garantía atribuida al trabajo tercerizado.'
            ),
            $actor
        );
        $this->assertSame(ServiceWorkOutcome::Completed, $report->outcome);
        $this->assertSame(
            ServiceOrderStatus::QualityControl,
            $order->fresh()->status
        );
    }

    public function test_unresolved_work_returns_order_to_diagnosis(): void
    {
        [, $actor, $order, $option] = $this->approvedOrder();
        $manager = app(ServiceWorkManager::class);
        $work = $manager->plan(
            new ServiceWorkItemData(
                serviceOrderId: $order->id,
                serviceQuoteOptionId: $option->id,
                title: 'Intentar recuperación de placa',
                description: 'Trabajo interno sujeto al estado del circuito.',
                executionMode: ServiceWorkExecutionMode::Internal,
                idempotencyKey: 'service:work:unresolved:1',
                assignedUserId: $actor->id
            ),
            $actor
        );
        $manager->startInternal(
            $work->id,
            'service:work:unresolved:start:1',
            $actor
        );

        $report = $manager->report(
            new ServiceWorkReportData(
                serviceWorkItemId: $work->id,
                outcome: ServiceWorkOutcome::Unresolved,
                resultSummary: 'No fue posible estabilizar la placa.',
                workPerformed: 'Mediciones y reemplazo de componentes de prueba.',
                idempotencyKey: 'service:work:unresolved:report:1',
                unresolvedReason: 'Daño interno multicapa sin reparación segura.'
            ),
            $actor
        );

        $this->assertSame(ServiceWorkOutcome::Unresolved, $report->outcome);
        $this->assertNull($report->warranty_days);
        $this->assertSame(
            ServiceWorkStatus::Unresolved,
            $work->fresh()->status
        );
        $this->assertSame(
            ServiceOrderStatus::Diagnosing,
            $order->fresh()->status
        );
    }

    public function test_all_planned_work_must_finish_before_quality_control(): void
    {
        [, $actor, $order, $option] = $this->approvedOrder();
        $manager = app(ServiceWorkManager::class);
        $first = $this->internalWork(
            $manager,
            $order,
            $option,
            $actor,
            'service:work:multi:1'
        );
        $second = $this->internalWork(
            $manager,
            $order,
            $option,
            $actor,
            'service:work:multi:2'
        );
        $manager->startInternal($first->id, 'multi:start:1', $actor);
        $manager->startInternal($second->id, 'multi:start:2', $actor);

        $manager->report(
            $this->completedReport($first, 'multi:report:1'),
            $actor
        );
        $this->assertSame(
            ServiceOrderStatus::InProgress,
            $order->fresh()->status
        );

        $manager->report(
            $this->completedReport($second, 'multi:report:2'),
            $actor
        );
        $this->assertSame(
            ServiceOrderStatus::QualityControl,
            $order->fresh()->status
        );
    }

    public function test_tenant_role_and_database_guards_reject_bypasses(): void
    {
        [$organization, $actor, $order, $option] = $this->approvedOrder();
        $manager = app(ServiceWorkManager::class);
        $viewer = $this->user($organization, UserRole::Viewer);
        $data = new ServiceWorkItemData(
            serviceOrderId: $order->id,
            serviceQuoteOptionId: $option->id,
            title: 'Trabajo protegido',
            description: 'No puede registrarlo un observador.',
            executionMode: ServiceWorkExecutionMode::Internal,
            idempotencyKey: 'service:work:guard:1',
            assignedUserId: $actor->id
        );

        $this->assertDomainFailure(fn () => $manager->plan($data, $viewer));
        $work = $manager->plan($data, $actor);

        $this->assertQueryRejected(
            fn () => DB::table('service_work_items')
                ->where('id', $work->id)
                ->update(['title' => 'Alcance adulterado'])
        );
        $this->assertQueryRejected(
            fn () => DB::table('service_work_items')
                ->where('id', $work->id)
                ->update(['status' => ServiceWorkStatus::Completed->value])
        );
        $this->assertQueryRejected(
            fn () => DB::table('service_work_status_histories')
                ->where('service_work_item_id', $work->id)
                ->delete()
        );
    }

    /**
     * @return array{Organization, User, ServiceOrder, ServiceQuoteOption}
     */
    private function approvedOrder(): array
    {
        $organization = Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();
        $actor = $this->user($organization, UserRole::Operator);
        $customer = $this->party($organization, 'Cliente notebook');
        $location = InventoryLocation::query()
            ->forOrganization($organization->id)
            ->where(
                'normalized_name',
                InventoryLocation::normalizeName('Recepción')
            )
            ->firstOrFail();
        $order = app(ServiceOrderIntakeManager::class)->create(
            new ServiceOrderIntakeData(
                assetType: ServiceAssetType::Notebook,
                brandName: 'Lenovo',
                modelName: 'IdeaPad 3',
                identifiers: [
                    new ServiceAssetIdentifierData(
                        ServiceIdentifierType::SerialNumber,
                        'NB-WORK-001'
                    ),
                ],
                intakeLocationId: $location->id,
                customerReportedIssue: 'Equipo lento y con fallas de teclado.',
                idempotencyKey: 'service:intake:work:1',
                customerBusinessPartyId: $customer->id,
                intakeObservations: 'Disco mecánico con ruidos.',
                receivedAccessories: 'Notebook y cargador.'
            ),
            $actor
        );
        $assessment = app(ServiceAssessmentManager::class);
        $assessment->recordDiagnostic(
            new ServiceDiagnosticData(
                serviceOrderId: $order->id,
                summary: 'Disco degradado y teclado dañado.',
                recommendation: 'Instalar SSD y reemplazar teclado.',
                findings: [
                    new ServiceDiagnosticFindingData(
                        ServiceFindingSeverity::Critical,
                        'Almacenamiento',
                        'El disco informa errores SMART.'
                    ),
                ],
                idempotencyKey: 'service:diagnostic:work:1'
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
                                'SSD 480 GB',
                                '1',
                                6000000
                            ),
                            new ServiceQuoteLineData(
                                ServiceQuoteLineType::Labor,
                                'Instalación, migración y pruebas',
                                '1',
                                3500000
                            ),
                        ],
                        recommended: true
                    ),
                ],
                idempotencyKey: 'service:quote:work:1'
            ),
            $actor
        );
        $option = $quote->options->sole();
        $assessment->recordDecision(
            new ServiceQuoteDecisionData(
                serviceQuoteId: $quote->id,
                decision: ServiceQuoteDecisionType::Approved,
                customerName: 'Cliente notebook',
                channel: 'WhatsApp',
                idempotencyKey: 'service:decision:work:1',
                serviceQuoteOptionId: $option->id
            ),
            $actor
        );

        return [$organization, $actor, $order, $option];
    }

    private function internalWork(
        ServiceWorkManager $manager,
        ServiceOrder $order,
        ServiceQuoteOption $option,
        User $actor,
        string $key
    ): ServiceWorkItem {
        return $manager->plan(
            new ServiceWorkItemData(
                serviceOrderId: $order->id,
                serviceQuoteOptionId: $option->id,
                title: 'Tarea '.$key,
                description: 'Trabajo interno perteneciente al alcance.',
                executionMode: ServiceWorkExecutionMode::Internal,
                idempotencyKey: $key,
                assignedUserId: $actor->id
            ),
            $actor
        );
    }

    private function completedReport(
        ServiceWorkItem $work,
        string $key
    ): ServiceWorkReportData {
        return new ServiceWorkReportData(
            serviceWorkItemId: $work->id,
            outcome: ServiceWorkOutcome::Completed,
            resultSummary: 'Tarea completada y verificada.',
            workPerformed: 'Ejecución técnica conforme al alcance.',
            idempotencyKey: $key
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
