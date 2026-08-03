<?php

namespace Tests\Feature\Service;

use App\Domain\Service\ServiceAssetIdentifierData;
use App\Domain\Service\ServiceOrderIntakeData;
use App\Domain\Service\ServiceOrderIntakeManager;
use App\Enums\ServiceAssetType;
use App\Enums\ServiceIdentifierType;
use App\Enums\ServiceOrderStatus;
use App\Enums\ServiceQuoteDecisionType;
use App\Enums\UserRole;
use App\Http\Middleware\RequireOrganization;
use App\Models\BusinessParty;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ServiceOrder;
use App\Models\ServiceQuote;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ServiceAssessmentHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_assessment_routes_are_explicit_and_viewer_is_read_only(): void
    {
        $organization = $this->organization();
        $viewer = $this->user($organization, UserRole::Viewer);
        $order = $this->order(
            $organization,
            $this->user($organization, UserRole::Operator)
        );

        $routeAbilities = [
            'service-orders.diagnostics.store' =>
                'can:record-service-diagnostics',
            'service-orders.quotes.store' => 'can:issue-service-quotes',
            'service-orders.quotes.decisions.store' =>
                'can:record-service-quote-decisions',
        ];

        foreach ($routeAbilities as $name => $ability) {
            $route = app('router')->getRoutes()->getByName($name);

            $this->assertSame(['POST'], $route->methods());
            $this->assertContains(
                RequireOrganization::class,
                $route->gatherMiddleware()
            );
            $this->assertContains($ability, $route->gatherMiddleware());
        }

        $this->actingAs($viewer)
            ->get(route('service-orders.diagnostics.create', $order))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->post(
                route('service-orders.diagnostics.store', $order),
                $this->diagnosticPayload()
            )->assertForbidden();

        $this->assertDatabaseCount('service_diagnostics', 0);
    }

    public function test_operator_completes_diagnosis_quote_and_approval(): void
    {
        $organization = $this->organization();
        $operator = $this->user($organization, UserRole::Operator);
        $order = $this->order($organization, $operator);

        $this->actingAs($operator)
            ->get(route('service-orders.diagnostics.create', $order))
            ->assertOk()
            ->assertSee('Diagnóstico técnico · Orden #1')
            ->assertSee('Declarado al ingresar');

        $this->actingAs($operator)
            ->post(
                route('service-orders.diagnostics.store', $order),
                $this->diagnosticPayload()
            )
            ->assertRedirect(route('service-orders.show', $order))
            ->assertSessionHas(
                'success',
                'Diagnóstico revisión 1 registrado.'
            );

        $this->assertSame(
            ServiceOrderStatus::Diagnosing,
            $order->fresh()->status
        );
        $this->assertDatabaseCount('service_diagnostics', 1);
        $this->assertDatabaseCount('service_diagnostic_findings', 2);

        $this->actingAs($operator)
            ->get(route('service-orders.quotes.create', $order))
            ->assertOk()
            ->assertSee('Presupuesto · Orden #1')
            ->assertSee('Diagnóstico vigente');

        $this->actingAs($operator)
            ->post(
                route('service-orders.quotes.store', $order),
                $this->quotePayload()
            )
            ->assertRedirect(route('service-orders.show', $order))
            ->assertSessionHas(
                'success',
                'Presupuesto revisión 1 emitido.'
            );

        $quote = ServiceQuote::query()->with('options.lines')->sole();

        $this->assertSame(9500000, $quote->options->sole()->total_minor);
        $this->assertSame(
            ServiceOrderStatus::AwaitingApproval,
            $order->fresh()->status
        );

        $this->actingAs($operator)
            ->get(route(
                'service-orders.quotes.decisions.create',
                [$order, $quote]
            ))
            ->assertOk()
            ->assertSee('Decisión del presupuesto · Orden #1')
            ->assertSee('$ 95.000,00');

        $this->actingAs($operator)
            ->post(route(
                'service-orders.quotes.decisions.store',
                [$order, $quote]
            ), $this->decisionPayload(
                ServiceQuoteDecisionType::Approved,
                $quote->options->sole()->id
            ))
            ->assertRedirect(route('service-orders.show', $order))
            ->assertSessionHas(
                'success',
                'Decisión del cliente registrada: Aprobado.'
            );

        $this->assertSame(
            ServiceOrderStatus::InProgress,
            $order->fresh()->status
        );
        $this->assertDatabaseHas('service_quote_decisions', [
            'service_quote_id' => $quote->id,
            'decision' => ServiceQuoteDecisionType::Approved->value,
            'service_quote_option_id' => $quote->options->sole()->id,
        ]);

        $this->actingAs($operator)
            ->get(route('service-orders.show', $order))
            ->assertOk()
            ->assertSee('Diagnósticos y presupuestos')
            ->assertSee('Revisión 1')
            ->assertSee('Aprobado')
            ->assertSee('$ 95.000,00');
    }

    public function test_no_data_risk_discards_stale_free_text(): void
    {
        $organization = $this->organization();
        $operator = $this->user($organization, UserRole::Operator);
        $order = $this->order(
            $organization,
            $operator,
            'http-assessment-without-data-risk'
        );
        $payload = $this->diagnosticPayload();
        $payload['data_risk_present'] = '0';
        $payload['data_risk_notes'] = 'Texto anterior que debe descartarse.';

        $this->actingAs($operator)->post(
            route('service-orders.diagnostics.store', $order),
            $payload
        )->assertRedirect(route('service-orders.show', $order));

        $this->assertDatabaseHas('service_diagnostics', [
            'service_order_id' => $order->id,
            'data_risk_notes' => null,
        ]);
    }

    public function test_rejection_preserves_first_quote_and_allows_revision_two(): void
    {
        $organization = $this->organization();
        $operator = $this->user($organization, UserRole::Operator);
        $order = $this->diagnosedOrder($organization, $operator);

        $this->actingAs($operator)->post(
            route('service-orders.quotes.store', $order),
            $this->quotePayload()
        )->assertRedirect();

        $first = ServiceQuote::query()->with('options')->sole();

        $this->actingAs($operator)->post(route(
            'service-orders.quotes.decisions.store',
            [$order, $first]
        ), $this->decisionPayload(ServiceQuoteDecisionType::Rejected))
            ->assertRedirect(route('service-orders.show', $order));

        $this->assertSame(
            ServiceOrderStatus::Diagnosing,
            $order->fresh()->status
        );

        $secondPayload = $this->quotePayload();
        $secondPayload['idempotency_key'] =
            'service-ui:quote:'.Str::uuid();
        $secondPayload['options'][0]['label'] = 'Alternativa ajustada';

        $this->actingAs($operator)->post(
            route('service-orders.quotes.store', $order),
            $secondPayload
        )->assertRedirect();

        $this->assertDatabaseCount('service_quotes', 2);
        $this->assertSame([1, 2], ServiceQuote::query()
            ->orderBy('revision')
            ->pluck('revision')
            ->map(fn ($revision): int => (int) $revision)
            ->all());

        $this->actingAs($operator)
            ->get(route('service-orders.show', $order))
            ->assertOk()
            ->assertSee('Rechazado')
            ->assertSee('Alternativa ajustada')
            ->assertSee('Revisión 2');
    }

    public function test_validation_blocks_inconsistent_assessment_facts(): void
    {
        $organization = $this->organization();
        $operator = $this->user($organization, UserRole::Operator);
        $order = $this->order($organization, $operator);

        $invalidDiagnostic = $this->diagnosticPayload();
        $invalidDiagnostic['findings'] = [];

        $this->actingAs($operator)->post(
            route('service-orders.diagnostics.store', $order),
            $invalidDiagnostic
        )->assertSessionHasErrors('findings');

        $this->assertDatabaseCount('service_diagnostics', 0);

        $order = $this->diagnosedOrder(
            $organization,
            $operator,
            'http-validation-second-order'
        );
        $invalidQuote = $this->quotePayload();
        $invalidQuote['options'][] = $invalidQuote['options'][0];

        $this->actingAs($operator)->post(
            route('service-orders.quotes.store', $order),
            $invalidQuote
        )->assertSessionHasErrors('options');

        $this->assertDatabaseCount('service_quotes', 0);

        $this->actingAs($operator)->post(
            route('service-orders.quotes.store', $order),
            $this->quotePayload()
        )->assertRedirect();

        $quote = ServiceQuote::query()->with('options')->sole();

        $this->actingAs($operator)->post(route(
            'service-orders.quotes.decisions.store',
            [$order, $quote]
        ), $this->decisionPayload(ServiceQuoteDecisionType::Approved))
            ->assertSessionHasErrors('service_quote_option_id');

        $this->actingAs($operator)->post(route(
            'service-orders.quotes.decisions.store',
            [$order, $quote]
        ), $this->decisionPayload(
            ServiceQuoteDecisionType::Rejected,
            null,
            ['reason' => null]
        ))->assertSessionHasErrors('reason');

        $this->assertDatabaseCount('service_quote_decisions', 0);
        $this->assertSame(
            ServiceOrderStatus::AwaitingApproval,
            $order->fresh()->status
        );
    }

    public function test_assessment_endpoints_do_not_cross_organization_boundary(): void
    {
        $organization = $this->organization();
        $other = Organization::query()->create([
            'name' => 'Taller privado ajeno',
            'slug' => 'taller-privado-ajeno-'.Str::lower(Str::random(6)),
            'active' => true,
        ]);
        $operator = $this->user($organization, UserRole::Operator);
        $foreignOperator = $this->user($other, UserRole::Operator);
        $foreignOrder = $this->order(
            $other,
            $foreignOperator,
            'foreign-assessment-http'
        );

        $this->actingAs($operator)
            ->get(route(
                'service-orders.diagnostics.create',
                $foreignOrder
            ))->assertNotFound();

        $this->actingAs($operator)
            ->post(route(
                'service-orders.diagnostics.store',
                $foreignOrder
            ), $this->diagnosticPayload())
            ->assertNotFound();

        $this->assertDatabaseCount('service_diagnostics', 0);
    }

    private function diagnosedOrder(
        Organization $organization,
        User $operator,
        string $key = 'http-assessment-order'
    ): ServiceOrder {
        $order = $this->order($organization, $operator, $key);

        $this->actingAs($operator)->post(
            route('service-orders.diagnostics.store', $order),
            $this->diagnosticPayload()
        )->assertRedirect();

        return $order->fresh();
    }

    private function order(
        Organization $organization,
        User $operator,
        string $key = 'http-assessment-order'
    ): ServiceOrder {
        $location = InventoryLocation::query()
            ->forOrganization($organization->id)
            ->first();

        if (! $location) {
            $location = InventoryLocation::query()->create([
                'organization_id' => $organization->id,
                'parent_id' => null,
                'name' => 'Recepción '.Str::random(5),
                'type' => 'receiving',
                'active' => true,
            ]);
        }

        $customer = BusinessParty::query()->create([
            'organization_id' => $organization->id,
            'party_type' => BusinessParty::TYPE_PERSON,
            'name' => 'Cliente UI evaluación',
        ]);

        return app(ServiceOrderIntakeManager::class)->create(
            new ServiceOrderIntakeData(
                assetType: ServiceAssetType::Notebook,
                brandName: 'Lenovo',
                modelName: 'IdeaPad 3',
                identifiers: [
                    new ServiceAssetIdentifierData(
                        ServiceIdentifierType::SerialNumber,
                        'NB-'.Str::upper(Str::random(10))
                    ),
                ],
                intakeLocationId: $location->id,
                customerReportedIssue: 'La notebook funciona muy lenta.',
                idempotencyKey: 'service:intake:'.$key,
                customerBusinessPartyId: $customer->id,
                intakeObservations: 'Teclado con dos teclas faltantes.',
                receivedAccessories: 'Notebook y cargador.'
            ),
            $operator
        );
    }

    /** @return array<string, mixed> */
    private function diagnosticPayload(): array
    {
        return [
            'summary' => 'La lentitud proviene del disco mecánico degradado.',
            'recommendation' => 'Instalar SSD y respaldar los datos.',
            'data_risk_notes' =>
                'Se requiere autorización para respaldar información.',
            'data_risk_present' => '1',
            'findings' => [
                [
                    'severity' => 'attention',
                    'category' => 'Teclado',
                    'description' => 'Faltan dos teclas.',
                    'evidence_notes' => null,
                ],
                [
                    'severity' => 'critical',
                    'category' => 'Almacenamiento',
                    'description' => 'El disco informa errores SMART.',
                    'evidence_notes' => 'Prueba extendida registrada.',
                ],
            ],
            'idempotency_key' =>
                'service-ui:diagnostic:'.Str::uuid(),
        ];
    }

    /** @return array<string, mixed> */
    private function quotePayload(): array
    {
        return [
            'currency_code' => 'ARS',
            'valid_until' => now()->addDays(7)->format('Y-m-d'),
            'terms' => 'Sujeto a disponibilidad del repuesto.',
            'options' => [[
                'label' => 'SSD y reinstalación',
                'description' => 'Conserva el teclado actual.',
                'recommended' => '1',
                'lines' => [
                    [
                        'type' => 'part',
                        'description' => 'SSD 480 GB',
                        'quantity' => '1',
                        'unit_price' => '60000.00',
                    ],
                    [
                        'type' => 'labor',
                        'description' => 'Instalación y configuración',
                        'quantity' => '1',
                        'unit_price' => '35000,00',
                    ],
                ],
            ]],
            'idempotency_key' => 'service-ui:quote:'.Str::uuid(),
        ];
    }

    /** @return array<string, mixed> */
    private function decisionPayload(
        ServiceQuoteDecisionType $decision,
        ?int $optionId = null,
        array $overrides = []
    ): array {
        return array_replace([
            'decision' => $decision->value,
            'service_quote_option_id' => $optionId,
            'customer_name' => 'Cliente UI evaluación',
            'customer_reference' => '+54 9 3447 000000',
            'channel' => 'WhatsApp',
            'reason' => $decision === ServiceQuoteDecisionType::Rejected
                ? 'Solicita una alternativa de menor importe.'
                : 'Autoriza la reparación completa.',
            'idempotency_key' =>
                'service-ui:decision:'.Str::uuid(),
        ], $overrides);
    }

    private function organization(): Organization
    {
        return Organization::query()
            ->where('slug', 'sulu-tv')
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
                [
                    'role' => $role,
                    'active' => true,
                ]
            )
        );

        return $user;
    }
}
