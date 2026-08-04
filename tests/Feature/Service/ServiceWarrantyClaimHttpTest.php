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
use App\Enums\ServiceFindingSeverity;
use App\Enums\ServiceIdentifierType;
use App\Enums\ServiceOrderStatus;
use App\Enums\ServiceQualityOutcome;
use App\Enums\ServiceQuoteDecisionType;
use App\Enums\ServiceQuoteLineType;
use App\Enums\ServiceWarrantyClaimOutcome;
use App\Enums\ServiceWarrantyClaimStatus;
use App\Enums\ServiceWarrantyTemporalStatus;
use App\Enums\ServiceWorkExecutionMode;
use App\Enums\ServiceWorkOutcome;
use App\Enums\UserRole;
use App\Http\Middleware\RequireOrganization;
use App\Models\BusinessParty;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ServiceOrder;
use App\Models\ServiceQuoteOption;
use App\Models\ServiceWarrantyClaim;
use App\Models\ServiceWarrantyClaimResolution;
use App\Models\ServiceWarrantyGrant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ServiceWarrantyClaimHttpTest extends TestCase
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

    public function test_routes_are_explicit_and_viewer_is_read_only(): void
    {
        $fixture = $this->deliveredWarranty('routes');
        $viewer = $this->user($fixture['organization'], UserRole::Viewer);
        $routeAbilities = [
            'service-orders.warranty-claims.create' => [
                'GET',
                'can:register-service-warranty-claims',
            ],
            'service-orders.warranty-claims.store' => [
                'POST',
                'can:register-service-warranty-claims',
            ],
            'service-orders.warranty-claims.resolution.create' => [
                'GET',
                'can:resolve-service-warranty-claims',
            ],
            'service-orders.warranty-claims.resolution.store' => [
                'POST',
                'can:resolve-service-warranty-claims',
            ],
            'service-orders.warranty-claims.return.create' => [
                'GET',
                'can:return-service-warranty-claims',
            ],
            'service-orders.warranty-claims.return.store' => [
                'POST',
                'can:return-service-warranty-claims',
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
            $this->assertContains($ability, $route->gatherMiddleware());
        }

        $this->actingAs($viewer)
            ->get(route(
                'service-orders.warranty-claims.create',
                [$fixture['order'], $fixture['warranty']]
            ))->assertForbidden();

        $this->actingAs($viewer)
            ->post(route(
                'service-orders.warranty-claims.store',
                [$fixture['order'], $fixture['warranty']]
            ), $this->claimPayload($fixture))
            ->assertForbidden();

        $this->assertDatabaseCount('service_warranty_claims', 0);
    }

    public function test_operator_registers_and_admin_accepts_claim(): void
    {
        $fixture = $this->deliveredWarranty('accepted-http');

        $this->actingAs($fixture['operator'])
            ->get(route(
                'service-orders.warranty-claims.create',
                [$fixture['order'], $fixture['warranty']]
            ))
            ->assertOk()
            ->assertSee('Registrar reclamo')
            ->assertSee('Se conservará la orden entregada');

        $this->actingAs($fixture['operator'])
            ->post(route(
                'service-orders.warranty-claims.store',
                [$fixture['order'], $fixture['warranty']]
            ), $this->claimPayload($fixture))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('service_warranty_claims', 1);
        $claim = ServiceWarrantyClaim::query()->sole();
        $corrective = $claim->correctiveOrder;

        $this->assertSame(
            ServiceWarrantyClaimStatus::PendingReview,
            $claim->status
        );
        $this->assertSame(ServiceOrderStatus::Received, $corrective->status);
        $this->assertSame(
            $fixture['order']->service_asset_id,
            $corrective->service_asset_id
        );

        $this->actingAs($fixture['operator'])
            ->get(route(
                'service-orders.warranty-claims.resolution.create',
                [$corrective, $claim]
            ))->assertForbidden();

        $this->actingAs($fixture['admin'])
            ->get(route(
                'service-orders.warranty-claims.resolution.create',
                [$corrective, $claim]
            ))
            ->assertOk()
            ->assertSee('Resolver reclamo')
            ->assertSee('aceptación total');

        $this->actingAs($fixture['admin'])
            ->post(route(
                'service-orders.warranty-claims.resolution.store',
                [$corrective, $claim]
            ), $this->resolutionPayload())
            ->assertRedirect(route('service-orders.show', $corrective));

        $claim->refresh();
        $this->assertSame(
            ServiceWarrantyClaimStatus::InCorrectiveWork,
            $claim->status
        );
        $this->assertSame(
            ServiceOrderStatus::InProgress,
            $corrective->fresh()->status
        );
        $this->assertSame(
            ServiceWarrantyClaimOutcome::Accepted,
            $claim->resolution->outcome
        );

        $this->actingAs($fixture['operator'])
            ->get(route('service-orders.show', $fixture['order']))
            ->assertOk()
            ->assertSee('Centro de reclamos de garantía')
            ->assertSee('Ver orden correctiva');

        $this->actingAs($fixture['operator'])
            ->get(route('service-orders.show', $corrective))
            ->assertOk()
            ->assertSee('Esta es una orden correctiva de garantía')
            ->assertSee('Resolución: Aceptada');
    }

    public function test_rejected_claim_is_validated_returned_and_tenant_scoped(): void
    {
        $fixture = $this->deliveredWarranty('rejected-http');
        $this->actingAs($fixture['operator'])->post(route(
            'service-orders.warranty-claims.store',
            [$fixture['order'], $fixture['warranty']]
        ), $this->claimPayload($fixture))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('service_warranty_claims', 1);
        $claim = ServiceWarrantyClaim::query()->sole();
        $corrective = $claim->correctiveOrder;
        $invalid = $this->resolutionPayload([
            'outcome' => ServiceWarrantyClaimOutcome::Rejected->value,
            'covered_scope' => 'Cobertura contradictoria.',
            'excluded_scope' => null,
        ]);

        $this->actingAs($fixture['admin'])->post(route(
            'service-orders.warranty-claims.resolution.store',
            [$corrective, $claim]
        ), $invalid)->assertSessionHasErrors([
            'covered_scope',
            'excluded_scope',
        ]);

        $this->assertDatabaseCount('service_warranty_claim_resolutions', 0);

        $this->actingAs($fixture['admin'])->post(route(
            'service-orders.warranty-claims.resolution.store',
            [$corrective, $claim]
        ), $this->resolutionPayload([
            'outcome' => ServiceWarrantyClaimOutcome::Rejected->value,
            'covered_scope' => null,
            'excluded_scope' => 'Daño por humedad posterior a la entrega.',
        ]))->assertRedirect(route('service-orders.show', $corrective));

        $this->assertSame(
            ServiceWarrantyClaimStatus::ReadyForReturn,
            $claim->fresh()->status
        );
        $this->assertSame(
            ServiceOrderStatus::ReadyForReturn,
            $corrective->fresh()->status
        );

        $this->actingAs($fixture['operator'])
            ->get(route(
                'service-orders.warranty-claims.return.create',
                [$corrective, $claim]
            ))
            ->assertOk()
            ->assertSee('Devolver equipo por garantía rechazada');

        $this->actingAs($fixture['operator'])
            ->post(route(
                'service-orders.warranty-claims.return.store',
                [$corrective, $claim]
            ), $this->returnPayload($fixture))
            ->assertRedirect(route('service-orders.show', $corrective))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            ServiceWarrantyClaimStatus::Closed,
            $claim->fresh()->status
        );
        $this->assertSame(
            ServiceOrderStatus::Cancelled,
            $corrective->fresh()->status
        );
        $this->assertDatabaseCount('service_warranty_claim_returns', 1);
        $this->assertDatabaseCount('service_deliveries', 1);

        $foreign = $this->newOrganization('Garantía HTTP ajena');
        $foreignOperator = $this->user($foreign, UserRole::Operator);

        $this->actingAs($foreignOperator)
            ->get(route(
                'service-orders.warranty-claims.create',
                [$fixture['order'], $fixture['warranty']]
            ))->assertNotFound();
    }

    public function test_expired_acceptance_requires_admin_exception_reason(): void
    {
        $base = CarbonImmutable::now()->subDays(120)->startOfSecond();
        CarbonImmutable::setTestNow($base);
        $fixture = $this->deliveredWarranty('expired-http', 30);
        CarbonImmutable::setTestNow($base->addDays(45));

        $this->actingAs($fixture['operator'])->post(route(
            'service-orders.warranty-claims.store',
            [$fixture['order'], $fixture['warranty']]
        ), $this->claimPayload($fixture))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('service_warranty_claims', 1);
        $claim = ServiceWarrantyClaim::query()->sole();
        $this->assertSame(
            ServiceWarrantyTemporalStatus::Expired,
            $claim->warranty_status_at_claim
        );

        $payload = $this->resolutionPayload();
        $payload['exception_reason'] = null;

        $this->actingAs($fixture['admin'])->post(route(
            'service-orders.warranty-claims.resolution.store',
            [$claim->correctiveOrder, $claim]
        ), $payload)->assertSessionHasErrors('exception_reason');

        $this->assertDatabaseCount('service_warranty_claim_resolutions', 0);

        $payload['exception_reason'] =
            'Se acepta por recurrencia documentada de la falla original.';

        $this->actingAs($fixture['admin'])->post(route(
            'service-orders.warranty-claims.resolution.store',
            [$claim->correctiveOrder, $claim]
        ), $payload)->assertRedirect();

        $resolution = ServiceWarrantyClaimResolution::query()->sole();
        $this->assertTrue($resolution->administrative_exception);
        $this->assertSame(
            ServiceWarrantyClaimStatus::InCorrectiveWork,
            $claim->fresh()->status
        );
    }

    /** @param array<string, mixed> $fixture */
    private function claimPayload(array $fixture): array
    {
        return [
            'intake_location_id' => $fixture['location']->id,
            'claimant_business_party_id' => $fixture['customer']->id,
            'claimant_name' => $fixture['customer']->name,
            'reported_issue' => 'La falla original volvió a presentarse.',
            'reentry_condition_notes' => 'Sin golpes ni daños nuevos.',
            'accessories_snapshot' => 'Equipo sin accesorios.',
            'channel' => 'Presencial',
            'claimed_at' => CarbonImmutable::now()->format('Y-m-d\TH:i'),
            'customer_reference' => 'GAR-HTTP',
            'idempotency_key' => 'service-ui:warranty-claim:'.Str::uuid(),
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function resolutionPayload(array $overrides = []): array
    {
        return array_replace([
            'outcome' => ServiceWarrantyClaimOutcome::Accepted->value,
            'technical_basis' => 'La falla coincide con el trabajo y componente garantizados.',
            'covered_scope' => 'Corrección integral de la falla reclamada.',
            'excluded_scope' => null,
            'exception_reason' => null,
            'notes' => null,
            'idempotency_key' => 'service-ui:warranty-resolution:'.Str::uuid(),
        ], $overrides);
    }

    /** @param array<string, mixed> $fixture */
    private function returnPayload(array $fixture): array
    {
        return [
            'recipient_business_party_id' => $fixture['customer']->id,
            'recipient_name' => $fixture['customer']->name,
            'recipient_document' => 'DNI 30111222',
            'condition_notes' => 'Se devuelve en la condición de reingreso.',
            'accessories_snapshot' => 'Equipo sin accesorios.',
            'notes' => 'Devolución presencial documentada.',
            'returned_at' => CarbonImmutable::now()->format('Y-m-d\TH:i'),
            'idempotency_key' => 'service-ui:warranty-return:'.Str::uuid(),
        ];
    }

    /**
     * @return array{
     *   organization: Organization,
     *   operator: User,
     *   admin: User,
     *   order: ServiceOrder,
     *   option: ServiceQuoteOption,
     *   customer: BusinessParty,
     *   location: InventoryLocation,
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
                    str_pad(
                        sprintf('%u', crc32($suffix)),
                        15,
                        '0',
                        STR_PAD_LEFT
                    )
                )],
                intakeLocationId: $location->id,
                customerReportedIssue: 'Pantalla rota y fallas de software.',
                idempotencyKey: 'service:http-warranty:intake:'.$suffix,
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
                idempotencyKey: 'service:http-warranty:diagnostic:'.$suffix
            ),
            $operator
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
                idempotencyKey: 'service:http-warranty:quote:'.$suffix
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
                idempotencyKey: 'service:http-warranty:decision:'.$suffix,
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
                idempotencyKey: 'service:http-warranty:work:'.$suffix,
                assignedUserId: $operator->id
            ),
            $operator
        );
        $workManager->startInternal(
            $work->id,
            'service:http-warranty:start:'.$suffix,
            $operator
        );
        $report = $workManager->report(
            new ServiceWorkReportData(
                serviceWorkItemId: $work->id,
                outcome: ServiceWorkOutcome::Completed,
                resultSummary: 'Equipo reparado y estable.',
                workPerformed: 'Módulo instalado y software saneado.',
                idempotencyKey: 'service:http-warranty:report:'.$suffix,
                warrantyDays: $warrantyDays,
                warrantyTerms: 'Garantía sobre mano de obra y componentes instalados.'
            ),
            $operator
        );
        $completion = app(ServiceCompletionManager::class);
        $inspection = $completion->inspect(
            new ServiceQualityInspectionData(
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
                idempotencyKey: 'service:http-warranty:quality:'.$suffix,
                notes: 'Prueba final completa.'
            ),
            $operator
        );
        $this->assertSame(ServiceQualityOutcome::Approved, $inspection->outcome);
        $delivery = $completion->deliver(
            new ServiceDeliveryData(
                serviceOrderId: $order->id,
                serviceQualityInspectionId: $inspection->id,
                recipientName: $customer->name,
                conditionNotes: 'Equipo encendido, probado y sin daños nuevos.',
                accessoriesSnapshot: 'Equipo sin accesorios.',
                customerConformity: true,
                idempotencyKey: 'service:http-warranty:delivery:'.$suffix,
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
            'customer' => $customer,
            'location' => $location,
            'warranty' => $warranty,
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
}
