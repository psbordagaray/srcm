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
use App\Http\Middleware\RequireOrganization;
use App\Models\BusinessParty;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ServiceCancellationRequest;
use App\Models\ServiceCancellationResolution;
use App\Models\ServiceOrder;
use App\Models\ServiceQuoteOption;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ServiceCancellationHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_routes_are_explicit_and_viewer_is_read_only(): void
    {
        [$organization, , $order] = $this->approvedOrder('routes');
        $viewer = $this->user($organization, UserRole::Viewer);
        $routeAbilities = [
            'service-orders.cancellation.request.create' => ['GET', 'can:request-service-cancellation'],
            'service-orders.cancellation.request.store' => ['POST', 'can:request-service-cancellation'],
            'service-orders.cancellation.recall.create' => ['GET', 'can:transfer-service-custody'],
            'service-orders.cancellation.recall.store' => ['POST', 'can:transfer-service-custody'],
            'service-orders.cancellation.resolution.create' => ['GET', 'can:resolve-service-cancellation'],
            'service-orders.cancellation.resolution.store' => ['POST', 'can:resolve-service-cancellation'],
            'service-orders.cancellation.return.create' => ['GET', 'can:return-cancelled-service-order'],
            'service-orders.cancellation.return.store' => ['POST', 'can:return-cancelled-service-order'],
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
            ->get(route('service-orders.cancellation.request.create', $order))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->post(
                route('service-orders.cancellation.request.store', $order),
                $this->requestPayload()
            )->assertForbidden();

        $this->assertDatabaseCount('service_cancellation_requests', 0);
    }

    public function test_operator_and_admin_complete_cancellation_and_return(): void
    {
        [$organization, $operator, $order, , $customer] =
            $this->approvedOrder('complete');
        $admin = $this->user($organization, UserRole::Admin);

        $this->actingAs($operator)
            ->get(route('service-orders.cancellation.request.create', $order))
            ->assertOk()
            ->assertSee('Solicitar cancelación · Orden #1')
            ->assertSee('El cliente desistió');

        $this->actingAs($operator)
            ->post(
                route('service-orders.cancellation.request.store', $order),
                $this->requestPayload($customer)
            )
            ->assertRedirect(route('service-orders.show', $order))
            ->assertSessionHas(
                'success',
                'Solicitud de cancelación registrada.'
            );

        $cancellation = ServiceCancellationRequest::query()->sole();
        $this->assertSame(
            ServiceOrderStatus::CancellationPending,
            $order->fresh()->status
        );

        $this->actingAs($operator)
            ->get(route(
                'service-orders.cancellation.resolution.create',
                [$order, $cancellation]
            ))->assertForbidden();

        $this->actingAs($admin)
            ->get(route(
                'service-orders.cancellation.resolution.create',
                [$order, $cancellation]
            ))
            ->assertOk()
            ->assertSee('Resolver cancelación · Orden #1')
            ->assertSee('Exposición capturada');

        $this->actingAs($admin)
            ->post(route(
                'service-orders.cancellation.resolution.store',
                [$order, $cancellation]
            ), $this->resolutionPayload())
            ->assertRedirect(route('service-orders.show', $order))
            ->assertSessionHas(
                'success',
                'Cancelación resuelta. La orden quedó lista para devolver.'
            );

        $resolution = ServiceCancellationResolution::query()->sole();
        $this->assertSame(
            ServiceOrderStatus::ReadyForReturn,
            $order->fresh()->status
        );

        $this->actingAs($operator)
            ->get(route(
                'service-orders.cancellation.return.create',
                [$order, $resolution]
            ))
            ->assertOk()
            ->assertSee('Entregar equipo cancelado · Orden #1');

        $this->actingAs($operator)
            ->post(route(
                'service-orders.cancellation.return.store',
                [$order, $resolution]
            ), $this->returnPayload($customer))
            ->assertRedirect(route('service-orders.show', $order))
            ->assertSessionHas(
                'success',
                'Equipo devuelto y orden cancelada definitivamente.'
            );

        $this->assertSame(
            ServiceOrderStatus::Cancelled,
            $order->fresh()->status
        );
        $this->assertDatabaseCount('service_cancellation_requests', 1);
        $this->assertDatabaseCount('service_cancellation_resolutions', 1);
        $this->assertDatabaseCount('service_cancellation_returns', 1);

        $this->actingAs($operator)
            ->get(route('service-orders.show', $order))
            ->assertOk()
            ->assertSee('Centro de cancelación y devolución')
            ->assertSee('Devolución final')
            ->assertSee($customer->name);
    }

    public function test_external_custody_returns_before_admin_resolution(): void
    {
        [$organization, $operator, $order, $option, $customer] =
            $this->approvedOrder('external-http');
        $admin = $this->user($organization, UserRole::Admin);
        $provider = $this->party($organization, 'Especialista HTTP');
        $workManager = app(ServiceWorkManager::class);
        $work = $workManager->plan(
            new ServiceWorkItemData(
                serviceOrderId: $order->id,
                serviceQuoteOptionId: $option->id,
                title: 'Reparación electrónica externa',
                description: 'Intervención derivada a especialista.',
                executionMode: ServiceWorkExecutionMode::External,
                idempotencyKey: 'service:http-cancel:external:work',
                providerBusinessPartyId: $provider->id
            ),
            $operator
        );
        $workManager->dispatchExternal(
            new ServiceWorkCustodyData(
                serviceWorkItemId: $work->id,
                conditionNotes: 'Equipo cerrado y documentado.',
                accessoriesSnapshot: 'Equipo sin accesorios.',
                idempotencyKey: 'service:http-cancel:external:dispatch'
            ),
            $operator
        );

        $this->actingAs($operator)->post(
            route('service-orders.cancellation.request.store', $order),
            $this->requestPayload($customer)
        )->assertRedirect(route('service-orders.show', $order));

        $cancellation = ServiceCancellationRequest::query()->sole();
        $resolutionPayload = $this->resolutionPayload();

        $this->actingAs($admin)->post(route(
            'service-orders.cancellation.resolution.store',
            [$order, $cancellation]
        ), $resolutionPayload)
            ->assertSessionHasErrors('cancellation');

        $this->assertDatabaseCount('service_cancellation_resolutions', 0);

        $this->actingAs($operator)
            ->get(route(
                'service-orders.cancellation.recall.create',
                [$order, $work]
            ))
            ->assertOk()
            ->assertSee('Recuperar custodia · Orden #1')
            ->assertSee($provider->name);

        $this->actingAs($operator)->post(route(
            'service-orders.cancellation.recall.store',
            [$order, $work]
        ), $this->recallPayload())
            ->assertRedirect(route('service-orders.show', $order));

        $this->assertSame(
            ServiceWorkStatus::Cancelled,
            $work->fresh()->status
        );

        $this->actingAs($admin)->post(route(
            'service-orders.cancellation.resolution.store',
            [$order, $cancellation]
        ), $resolutionPayload)
            ->assertRedirect(route('service-orders.show', $order));

        $this->assertSame(
            ServiceOrderStatus::ReadyForReturn,
            $order->fresh()->status
        );
        $this->assertDatabaseCount('service_cancellation_resolutions', 1);
    }

    public function test_validation_charge_rules_and_tenant_boundary_are_enforced(): void
    {
        [$organization, $operator, $order, , $customer] =
            $this->approvedOrder('validation');
        $admin = $this->user($organization, UserRole::Admin);

        $invalidRequest = $this->requestPayload($customer);
        $invalidRequest['reason'] = '';
        $invalidRequest['requester_name'] = '';

        $this->actingAs($operator)->post(
            route('service-orders.cancellation.request.store', $order),
            $invalidRequest
        )->assertSessionHasErrors(['reason', 'requester_name']);

        $this->assertDatabaseCount('service_cancellation_requests', 0);

        $this->actingAs($operator)->post(
            route('service-orders.cancellation.request.store', $order),
            $this->requestPayload($customer)
        )->assertRedirect();

        $cancellation = ServiceCancellationRequest::query()->sole();
        $charge = $this->resolutionPayload([
            'financial_outcome' => ServiceCancellationFinancialOutcome::CustomerCharge->value,
            'customer_charge' => null,
            'customer_acceptance_reference' => null,
        ]);

        $this->actingAs($admin)->post(route(
            'service-orders.cancellation.resolution.store',
            [$order, $cancellation]
        ), $charge)->assertSessionHasErrors([
            'customer_charge',
            'customer_acceptance_reference',
        ]);

        $this->assertDatabaseCount('service_cancellation_resolutions', 0);

        $foreign = Organization::query()->create([
            'name' => 'Taller HTTP ajeno',
            'slug' => 'taller-http-ajeno-'.Str::lower(Str::random(6)),
            'active' => true,
        ]);
        $foreignOperator = $this->user($foreign, UserRole::Operator);
        [, , $foreignOrder] = $this->approvedOrderFor(
            $foreign,
            $foreignOperator,
            'foreign'
        );

        $this->actingAs($operator)
            ->get(route(
                'service-orders.cancellation.request.create',
                $foreignOrder
            ))->assertNotFound();
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
        $operator = $this->user($organization, UserRole::Operator);

        return $this->approvedOrderFor($organization, $operator, $suffix);
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
    private function approvedOrderFor(
        Organization $organization,
        User $operator,
        string $suffix
    ): array {
        $customer = $this->party(
            $organization,
            'Cliente cancelación HTTP '.$suffix
        );
        $location = InventoryLocation::query()
            ->forOrganization($organization->id)
            ->first();

        if (! $location) {
            $location = InventoryLocation::query()->create([
                'organization_id' => $organization->id,
                'parent_id' => null,
                'name' => 'Recepción HTTP '.Str::random(5),
                'type' => 'receiving',
                'active' => true,
            ]);
        }

        $order = app(ServiceOrderIntakeManager::class)->create(
            new ServiceOrderIntakeData(
                assetType: ServiceAssetType::MobilePhone,
                brandName: 'Motorola',
                modelName: 'E22i',
                identifiers: [
                    new ServiceAssetIdentifierData(
                        ServiceIdentifierType::Imei,
                        str_pad(
                            sprintf('%u', crc32($organization->id.'-'.$suffix)),
                            15,
                            '0',
                            STR_PAD_LEFT
                        )
                    ),
                ],
                intakeLocationId: $location->id,
                customerReportedIssue: 'Pantalla rota por una caída.',
                idempotencyKey: 'service:intake:http-cancel:'.$organization->id.':'.$suffix,
                customerBusinessPartyId: $customer->id,
                intakeObservations: 'Chasis con marcas documentadas.',
                receivedAccessories: 'Equipo y funda negra.'
            ),
            $operator
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
                idempotencyKey: 'service:diagnostic:http-cancel:'.$organization->id.':'.$suffix
            ),
            $operator
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
                idempotencyKey: 'service:quote:http-cancel:'.$organization->id.':'.$suffix
            ),
            $operator
        );
        $option = $quote->options->sole();
        $assessment->recordDecision(
            new ServiceQuoteDecisionData(
                serviceQuoteId: $quote->id,
                decision: ServiceQuoteDecisionType::Approved,
                customerName: $customer->name,
                channel: 'WhatsApp',
                idempotencyKey: 'service:decision:http-cancel:'.$organization->id.':'.$suffix,
                serviceQuoteOptionId: $option->id
            ),
            $operator
        );

        return [$organization, $operator, $order, $option, $customer];
    }

    /** @return array<string, mixed> */
    private function requestPayload(?BusinessParty $customer = null): array
    {
        return [
            'reason' => ServiceCancellationReason::CustomerChangedMind->value,
            'requester_business_party_id' => $customer?->id,
            'requester_name' => $customer?->name ?? 'Cliente HTTP',
            'customer_reference' => '+54 9 3447 000000',
            'channel' => 'WhatsApp',
            'details' => 'Solicita detener la reparación y recuperar el equipo.',
            'idempotency_key' => 'service-ui:cancellation-request:'.Str::uuid(),
        ];
    }

    /** @return array<string, mixed> */
    private function recallPayload(): array
    {
        return [
            'condition_notes' => 'Retorna sin intervención irreversible.',
            'accessories_snapshot' => 'Equipo sin accesorios.',
            'idempotency_key' => 'service-ui:cancellation-recall:'.Str::uuid(),
        ];
    }

    /** @return array<string, mixed> */
    private function resolutionPayload(array $overrides = []): array
    {
        return array_replace([
            'financial_outcome' => ServiceCancellationFinancialOutcome::NoCharge->value,
            'currency_code' => 'ARS',
            'customer_charge' => null,
            'customer_acceptance_reference' => null,
            'work_disposition' => 'No se realizaron trabajos irreversibles.',
            'parts_disposition' => 'No existen repuestos pendientes.',
            'financial_disposition' => 'Cancelación sin cargo para el cliente.',
            'return_condition_notes' => 'Equipo listo para devolver.',
            'accessories_snapshot' => 'Equipo y funda negra.',
            'notes' => null,
            'idempotency_key' => 'service-ui:cancellation-resolution:'.Str::uuid(),
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function returnPayload(BusinessParty $customer): array
    {
        return [
            'recipient_business_party_id' => $customer->id,
            'recipient_name' => $customer->name,
            'recipient_document' => 'DNI 00000000',
            'condition_notes' => 'Se devuelve en la condición documentada.',
            'accessories_snapshot' => 'Equipo y funda negra.',
            'notes' => 'Entrega verificada en mostrador.',
            'idempotency_key' => 'service-ui:cancellation-return:'.Str::uuid(),
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
}
