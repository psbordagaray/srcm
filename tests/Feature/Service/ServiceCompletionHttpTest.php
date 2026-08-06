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
use App\Http\Middleware\RequireOrganization;
use App\Models\BusinessParty;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ServiceDelivery;
use App\Models\ServiceOrder;
use App\Models\ServiceQualityInspection;
use App\Models\ServiceWarrantyGrant;
use App\Models\ServiceWorkReport;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ServiceCompletionHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_routes_are_explicit_and_viewer_is_read_only(): void
    {
        $fixture = $this->completedOrder('routes');
        $viewer = $this->user(
            $fixture['organization'],
            UserRole::Viewer
        );
        $routeAbilities = [
            'service-orders.quality-inspections.create' => [
                'GET',
                'can:inspect-service-quality',
            ],
            'service-orders.quality-inspections.store' => [
                'POST',
                'can:inspect-service-quality',
            ],
            'service-orders.delivery.create' => [
                'GET',
                'can:deliver-service-orders',
            ],
            'service-orders.delivery.store' => [
                'POST',
                'can:deliver-service-orders',
            ],
        ];

        foreach ($routeAbilities as $name => [$method, $ability]) {
            $route = app('router')->getRoutes()->getByName($name);

            $this->assertNotNull($route, $name);
            $this->assertContains($method, $route->methods());
            $this->assertContains(
                RequireOrganization::class,
                $route->gatherMiddleware()
            );
            $this->assertContains(
                $ability,
                $route->gatherMiddleware()
            );
        }

        $this->actingAs($viewer)
            ->get(route(
                'service-orders.quality-inspections.create',
                $fixture['order']
            ))
            ->assertForbidden();

        $this->assertDatabaseCount(
            'service_quality_inspections',
            0
        );
    }

    public function test_operator_approves_quality_through_http(): void
    {
        $fixture = $this->completedOrder('quality-approved');

        $this->actingAs($fixture['operator'])
            ->get(route(
                'service-orders.quality-inspections.create',
                $fixture['order']
            ))
            ->assertOk()
            ->assertSee('Control de calidad')
            ->assertSee('Encendido y estabilidad')
            ->assertSee('Accesorios y elementos en custodia');

        $this->actingAs($fixture['operator'])
            ->post(route(
                'service-orders.quality-inspections.store',
                $fixture['order']
            ), $this->qualityPayload())
            ->assertRedirect(route(
                'service-orders.show',
                $fixture['order']
            ))
            ->assertSessionHasNoErrors();

        $inspection = ServiceQualityInspection::query()->sole();

        $this->assertSame(
            ServiceQualityOutcome::Approved,
            $inspection->outcome
        );
        $this->assertSame(6, $inspection->check_count);
        $this->assertSame(0, $inspection->failed_check_count);
        $this->assertSame(
            ServiceOrderStatus::ReadyForDelivery,
            $fixture['order']->fresh()->status
        );

        $this->actingAs($fixture['operator'])
            ->get(route(
                'service-orders.show',
                $fixture['order']
            ))
            ->assertOk()
            ->assertSee('Control de calidad y entrega')
            ->assertSee('Aprobado')
            ->assertSee('Registrar entrega');
    }

    public function test_failed_quality_returns_order_to_rework(): void
    {
        $fixture = $this->completedOrder('quality-rework');
        $payload = $this->qualityPayload();
        $payload['checks'][3]['passed'] = '0';
        $payload['checks'][3]['notes'] =
            'La conectividad Wi-Fi presenta cortes.';
        $payload['rework_reason'] =
            'Revisar antena y repetir el protocolo completo.';

        $this->actingAs($fixture['operator'])
            ->post(route(
                'service-orders.quality-inspections.store',
                $fixture['order']
            ), $payload)
            ->assertRedirect(route(
                'service-orders.show',
                $fixture['order']
            ))
            ->assertSessionHasNoErrors();

        $inspection = ServiceQualityInspection::query()->sole();

        $this->assertSame(
            ServiceQualityOutcome::ReworkRequired,
            $inspection->outcome
        );
        $this->assertSame(1, $inspection->failed_check_count);
        $this->assertSame(
            ServiceOrderStatus::InProgress,
            $fixture['order']->fresh()->status
        );
        $this->assertDatabaseCount('service_deliveries', 0);

        $this->actingAs($fixture['operator'])
            ->get(route(
                'service-orders.show',
                $fixture['order']
            ))
            ->assertOk()
            ->assertSee('Requiere retrabajo')
            ->assertSee('Revisar antena');
    }

    public function test_delivery_uses_latest_quality_and_generates_custody_and_warranty(): void
    {
        $fixture = $this->completedOrder('delivery');

        $this->actingAs($fixture['operator'])
            ->post(route(
                'service-orders.quality-inspections.store',
                $fixture['order']
            ), $this->qualityPayload())
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $inspection = ServiceQualityInspection::query()->sole();

        $this->actingAs($fixture['operator'])
            ->get(route(
                'service-orders.delivery.create',
                $fixture['order']
            ))
            ->assertOk()
            ->assertSee('Registrar entrega')
            ->assertSee($fixture['customer']->name)
            ->assertSee('Último control aprobado');

        $this->actingAs($fixture['operator'])
            ->post(route(
                'service-orders.delivery.store',
                $fixture['order']
            ), [
                'recipient_business_party_id' => $fixture['customer']->id,
                'recipient_name' => $fixture['customer']->name,
                'recipient_document' => 'DNI 30111222',
                'condition_notes' => 'Equipo encendido, probado y sin daños nuevos.',
                'accessories_snapshot' => 'Equipo y funda entregados.',
                'customer_conformity' => '1',
                'notes' => 'El cliente verificó el funcionamiento.',
                'delivered_at' => null,
                'idempotency_key' => 'service-ui:delivery:'.Str::uuid(),
            ])
            ->assertRedirect(route(
                'service-orders.show',
                $fixture['order']
            ))
            ->assertSessionHasNoErrors();

        $delivery = ServiceDelivery::query()
            ->with([
                'custodyEvent',
                'warranties.workReport',
            ])
            ->sole();

        $this->assertSame(
            $inspection->id,
            $delivery->service_quality_inspection_id
        );
        $this->assertSame(
            ServiceCustodyEventType::Delivered,
            $delivery->custodyEvent->event_type
        );
        $this->assertSame(
            $fixture['customer']->id,
            $delivery->recipient_business_party_id
        );
        $this->assertTrue($delivery->customer_conformity);
        $this->assertSame(
            ServiceOrderStatus::Delivered,
            $fixture['order']->fresh()->status
        );

        $grant = ServiceWarrantyGrant::query()->sole();

        $this->assertSame(
            $fixture['report']->id,
            $grant->service_work_report_id
        );
        $this->assertSame(90, $grant->warranty_days);

        $this->actingAs($fixture['operator'])
            ->get(route(
                'service-orders.show',
                $fixture['order']
            ))
            ->assertOk()
            ->assertSee('Entrega confirmada')
            ->assertSee('Garantías generadas')
            ->assertSee('90 días');
    }

    public function test_delivery_validation_rejects_foreign_recipient_and_missing_nonconformity_notes(): void
    {
        $fixture = $this->completedOrder('delivery-guards');

        $this->actingAs($fixture['operator'])
            ->post(route(
                'service-orders.quality-inspections.store',
                $fixture['order']
            ), $this->qualityPayload())
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $foreignOrganization = Organization::query()->create([
            'name' => 'Taller extranjero HTTP',
            'slug' => 'taller-extranjero-http',
            'active' => true,
        ]);
        $foreignRecipient = $this->party(
            $foreignOrganization,
            'Receptor ajeno HTTP'
        );
        $base = [
            'recipient_name' => 'Receptor autorizado',
            'recipient_document' => null,
            'condition_notes' => 'Equipo verificado.',
            'accessories_snapshot' => 'Equipo sin accesorios.',
            'customer_conformity' => '1',
            'notes' => null,
            'delivered_at' => null,
            'idempotency_key' => 'service-ui:delivery:'.Str::uuid(),
        ];

        $this->actingAs($fixture['operator'])
            ->post(route(
                'service-orders.delivery.store',
                $fixture['order']
            ), [
                ...$base,
                'recipient_business_party_id' => $foreignRecipient->id,
            ])
            ->assertSessionHasErrors(
                'recipient_business_party_id'
            );

        $this->actingAs($fixture['operator'])
            ->post(route(
                'service-orders.delivery.store',
                $fixture['order']
            ), [
                ...$base,
                'recipient_business_party_id' => null,
                'customer_conformity' => '0',
                'idempotency_key' => 'service-ui:delivery:'.Str::uuid(),
            ])
            ->assertSessionHasErrors('notes');

        $this->assertDatabaseCount('service_deliveries', 0);
        $this->assertSame(
            ServiceOrderStatus::ReadyForDelivery,
            $fixture['order']->fresh()->status
        );
    }

    /**
     * @return array{
     *   organization: Organization,
     *   operator: User,
     *   order: ServiceOrder,
     *   customer: BusinessParty,
     *   report: ServiceWorkReport
     * }
     */
    private function completedOrder(string $suffix): array
    {
        $organization = Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();
        $operator = $this->user(
            $organization,
            UserRole::Operator
        );
        $customer = $this->party(
            $organization,
            'Cliente calidad '.$suffix
        );
        $location = $this->location($organization);
        $order = app(ServiceOrderIntakeManager::class)->create(
            new ServiceOrderIntakeData(
                assetType: ServiceAssetType::MobilePhone,
                brandName: 'Motorola',
                modelName: 'E22i '.$suffix,
                identifiers: [new ServiceAssetIdentifierData(
                    ServiceIdentifierType::Imei,
                    str_pad(
                        sprintf('%u', crc32($suffix)),
                        15,
                        '0',
                        STR_PAD_LEFT
                    )
                )],
                intakeLocationId: $location->id,
                customerReportedIssue: 'Pantalla rota y equipo inestable.',
                idempotencyKey: 'service:http-completion:intake:'.$suffix,
                customerBusinessPartyId: $customer->id,
                intakeObservations: 'Pantalla anterior no original.',
                receivedAccessories: 'Equipo y funda.'
            ),
            $operator
        );
        $assessment = app(ServiceAssessmentManager::class);
        $assessment->recordDiagnostic(
            new ServiceDiagnosticData(
                serviceOrderId: $order->id,
                summary: 'Módulo dañado.',
                recommendation: 'Reemplazar módulo y verificar funciones.',
                findings: [new ServiceDiagnosticFindingData(
                    ServiceFindingSeverity::Critical,
                    'Pantalla',
                    'El módulo no entrega imagen correctamente.'
                )],
                idempotencyKey: 'service:http-completion:diagnostic:'.$suffix
            ),
            $operator
        );
        $quote = $assessment->issueQuote(
            new ServiceQuoteData(
                serviceOrderId: $order->id,
                options: [new ServiceQuoteOptionData(
                    label: 'Reparación completa',
                    lines: [new ServiceQuoteLineData(
                        ServiceQuoteLineType::Labor,
                        'Cambio de módulo y pruebas',
                        '1',
                        4500000
                    )],
                    recommended: true
                )],
                idempotencyKey: 'service:http-completion:quote:'.$suffix
            ),
            $operator
        );
        $option = $quote->options->sole();
        $assessment->recordDecision(
            new ServiceQuoteDecisionData(
                serviceQuoteId: $quote->id,
                decision: ServiceQuoteDecisionType::Approved,
                customerName: $customer->name,
                channel: 'Presencial',
                idempotencyKey: 'service:http-completion:decision:'.$suffix,
                serviceQuoteOptionId: $option->id
            ),
            $operator
        );
        $workManager = app(ServiceWorkManager::class);
        $work = $workManager->plan(
            new ServiceWorkItemData(
                serviceOrderId: $order->id,
                serviceQuoteOptionId: $option->id,
                title: 'Reparación integral',
                description: 'Cambio de módulo y pruebas funcionales.',
                executionMode: ServiceWorkExecutionMode::Internal,
                idempotencyKey: 'service:http-completion:work:'.$suffix,
                assignedUserId: $operator->id
            ),
            $operator
        );
        $workManager->startInternal(
            $work->id,
            'service:http-completion:start:'.$suffix,
            $operator
        );
        $report = $workManager->report(
            new ServiceWorkReportData(
                serviceWorkItemId: $work->id,
                outcome: ServiceWorkOutcome::Completed,
                resultSummary: 'Equipo reparado y listo para control.',
                workPerformed: 'Módulo instalado y pruebas preliminares.',
                idempotencyKey: 'service:http-completion:report:'.$suffix,
                warrantyDays: 90,
                warrantyTerms: 'Garantía sobre mano de obra y componentes instalados.'
            ),
            $operator
        );

        $this->assertSame(
            ServiceOrderStatus::QualityControl,
            $order->fresh()->status
        );

        return [
            'organization' => $organization,
            'operator' => $operator,
            'order' => $order->fresh(),
            'customer' => $customer,
            'report' => $report,
        ];
    }

    /** @return array<string, mixed> */
    private function qualityPayload(): array
    {
        $checks = [
            ['code' => 'power', 'notes' => null],
            ['code' => 'charging', 'notes' => null],
            ['code' => 'primary_function', 'notes' => null],
            ['code' => 'connectivity', 'notes' => null],
            ['code' => 'physical_condition', 'notes' => null],
            ['code' => 'accessories', 'notes' => null],
        ];

        return [
            'checks' => collect($checks)
                ->map(fn (array $check): array => [
                    ...$check,
                    'passed' => '1',
                ])
                ->all(),
            'condition_notes' => 'Equipo encendido, estable y sin daños nuevos.',
            'accessories_snapshot' => 'Equipo y funda verificados.',
            'rework_reason' => null,
            'notes' => 'Protocolo final completo.',
            'idempotency_key' => 'service-ui:quality-inspection:'.Str::uuid(),
        ];
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

    private function location(
        Organization $organization
    ): InventoryLocation {
        return InventoryLocation::query()
            ->forOrganization($organization->id)
            ->orderBy('id')
            ->firstOrFail();
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
}
