<?php

namespace Tests\Feature\Service;

use App\Domain\Inventory\InventoryMovementConfirmer;
use App\Domain\Inventory\InventoryMovementCreator;
use App\Domain\Inventory\InventoryMovementDraftData;
use App\Domain\Inventory\InventoryMovementLineData;
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
use App\Enums\InventoryCondition;
use App\Enums\InventoryLocationType;
use App\Enums\InventoryMovementStatus;
use App\Enums\InventoryMovementType;
use App\Enums\ServiceAssetType;
use App\Enums\ServiceFindingSeverity;
use App\Enums\ServiceIdentifierType;
use App\Enums\ServiceOrderStatus;
use App\Enums\ServicePartSource;
use App\Enums\ServiceQualityOutcome;
use App\Enums\ServiceQuoteDecisionType;
use App\Enums\ServiceQuoteLineType;
use App\Enums\ServiceWarrantyClaimOutcome;
use App\Enums\ServiceWarrantyClaimStatus;
use App\Enums\ServiceWorkExecutionMode;
use App\Enums\ServiceWorkOutcome;
use App\Enums\UserRole;
use App\Http\Middleware\RequireOrganization;
use App\Models\BusinessParty;
use App\Models\CatalogProduct;
use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductCategory;
use App\Models\ServiceOrder;
use App\Models\ServicePartConsumption;
use App\Models\ServicePartPurchase;
use App\Models\ServicePartRequirement;
use App\Models\ServiceQuoteOption;
use App\Models\ServiceWarrantyClaim;
use App\Models\ServiceWarrantyGrant;
use App\Models\ServiceWorkItem;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ServicePartHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_routes_are_explicit_and_viewer_is_read_only(): void
    {
        $fixture = $this->approvedWork('routes');
        $viewer = $this->user(
            $fixture['organization'],
            UserRole::Viewer
        );
        $routeAbilities = [
            'service-orders.part-requirements.create' => [
                'GET',
                'can:plan-service-parts',
            ],
            'service-orders.part-requirements.store' => [
                'POST',
                'can:plan-service-parts',
            ],
            'service-orders.part-purchases.create' => [
                'GET',
                'can:record-service-part-purchases',
            ],
            'service-orders.part-purchases.store' => [
                'POST',
                'can:record-service-part-purchases',
            ],
            'service-orders.part-requirements.consume.create' => [
                'GET',
                'can:consume-service-parts',
            ],
            'service-orders.part-requirements.consume.store' => [
                'POST',
                'can:consume-service-parts',
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
                'service-orders.part-requirements.create',
                [$fixture['order'], $fixture['work']]
            ))
            ->assertForbidden();

        $this->assertDatabaseCount('service_part_requirements', 0);
    }

    public function test_operator_plans_and_consumes_stock_part(): void
    {
        $fixture = $this->approvedWork('stock-http');
        $product = $this->product(
            'Módulo Motorola E22i HTTP',
            'PART-HTTP-STOCK'
        );
        $this->seedStock(
            $fixture['operator'],
            $product,
            $fixture['location'],
            '2'
        );
        $quoteLine = $fixture['option']->lines->first(
            fn ($line): bool => $line->line_type === ServiceQuoteLineType::Part
        );

        $this->actingAs($fixture['operator'])
            ->get(route(
                'service-orders.part-requirements.create',
                [$fixture['order'], $fixture['work']]
            ))
            ->assertOk()
            ->assertSee('Planificar repuesto')
            ->assertSee($quoteLine->description);

        $this->actingAs($fixture['operator'])
            ->post(route(
                'service-orders.part-requirements.store',
                [$fixture['order'], $fixture['work']]
            ), [
                'service_quote_line_id' => $quoteLine->id,
                'catalog_product_id' => $product->id,
                'condition' => InventoryCondition::New->value,
                'source' => ServicePartSource::Stock->value,
                'required_quantity' => '999',
                'idempotency_key' => 'service-ui:part-requirement:'.Str::uuid(),
            ])
            ->assertRedirect(route(
                'service-orders.show',
                $fixture['order']
            ))
            ->assertSessionHasNoErrors();

        $requirement = ServicePartRequirement::query()->sole();

        $this->assertSame(
            (string) $quoteLine->quantity,
            (string) $requirement->required_quantity
        );
        $this->assertSame(
            ServicePartSource::Stock,
            $requirement->source
        );

        app(ServiceWorkManager::class)->startInternal(
            $fixture['work']->id,
            'service:http-parts:stock:start',
            $fixture['operator']
        );

        $this->actingAs($fixture['operator'])
            ->get(route(
                'service-orders.part-requirements.consume.create',
                [$fixture['order'], $requirement]
            ))
            ->assertOk()
            ->assertSee('Registrar consumo de repuesto')
            ->assertSee($fixture['location']->name);

        $this->actingAs($fixture['operator'])
            ->post(route(
                'service-orders.part-requirements.consume.store',
                [$fixture['order'], $requirement]
            ), [
                'quantity' => '1',
                'source_location_id' => $fixture['location']->id,
                'service_part_purchase_line_id' => null,
                'idempotency_key' => 'service-ui:part-consumption:'.Str::uuid(),
            ])
            ->assertRedirect(route(
                'service-orders.show',
                $fixture['order']
            ))
            ->assertSessionHasNoErrors();

        $consumption = ServicePartConsumption::query()->sole();
        $this->assertNotNull(
            $consumption->inventory_movement_line_id
        );

        $issue = InventoryMovement::query()
            ->where('type', InventoryMovementType::Issue->value)
            ->sole();
        $this->assertSame(
            InventoryMovementStatus::Confirmed,
            $issue->status
        );
        $this->assertSame(
            '1.000000',
            InventoryBalance::query()
                ->where(
                    'organization_id',
                    $fixture['organization']->id
                )
                ->where('catalog_product_id', $product->id)
                ->where(
                    'inventory_location_id',
                    $fixture['location']->id
                )
                ->where(
                    'condition',
                    InventoryCondition::New->value
                )
                ->value('quantity')
        );

        $this->actingAs($fixture['operator'])
            ->get(route(
                'service-orders.show',
                $fixture['order']
            ))
            ->assertOk()
            ->assertSee('Repuestos de la reparación')
            ->assertSee($product->name);
    }

    public function test_direct_purchase_is_recorded_and_consumed_without_stock(): void
    {
        $fixture = $this->approvedWork('purchase-http');
        $product = $this->product(
            'Pantalla compra afectada HTTP',
            'PART-HTTP-DIRECT'
        );
        $supplier = $this->supplier(
            $fixture['organization'],
            'Daniel de Word Cell HTTP'
        );
        $quoteLine = $fixture['option']->lines->first(
            fn ($line): bool => $line->line_type === ServiceQuoteLineType::Part
        );

        $this->actingAs($fixture['operator'])
            ->post(route(
                'service-orders.part-requirements.store',
                [$fixture['order'], $fixture['work']]
            ), [
                'service_quote_line_id' => $quoteLine->id,
                'catalog_product_id' => $product->id,
                'condition' => InventoryCondition::New->value,
                'source' => ServicePartSource::DirectPurchase->value,
                'idempotency_key' => 'service-ui:part-requirement:'.Str::uuid(),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $requirement = ServicePartRequirement::query()->sole();

        $this->assertSame(
            ServiceOrderStatus::AwaitingParts,
            $fixture['order']->fresh()->status
        );

        $this->actingAs($fixture['operator'])
            ->get(route(
                'service-orders.part-purchases.create',
                $fixture['order']
            ))
            ->assertOk()
            ->assertSee('Registrar compra de repuestos')
            ->assertSee($supplier->party->name);

        $this->actingAs($fixture['operator'])
            ->post(route(
                'service-orders.part-purchases.store',
                $fixture['order']
            ), [
                'supplier_id' => $supplier->id,
                'currency_code' => 'ARS',
                'purchased_at' => now()->format('Y-m-d\TH:i'),
                'logistics_cost' => '1000.00',
                'document_reference' => 'HTTP-PART-001',
                'notes' => 'Compra recibida para la orden.',
                'lines' => [[
                    'service_part_requirement_id' => $requirement->id,
                    'quantity' => (string) $requirement->required_quantity,
                    'unit_cost' => '50000.00',
                ]],
                'idempotency_key' => 'service-ui:part-purchase:'.Str::uuid(),
            ])
            ->assertRedirect(route(
                'service-orders.show',
                $fixture['order']
            ))
            ->assertSessionHasNoErrors();

        $purchase = ServicePartPurchase::query()
            ->with('lines')
            ->sole();

        $this->assertSame(5000000, $purchase->parts_total_minor);
        $this->assertSame(
            100000,
            $purchase->logistics_cost_minor
        );
        $this->assertSame(5100000, $purchase->grand_total_minor);
        $this->assertSame(
            ServiceOrderStatus::InProgress,
            $fixture['order']->fresh()->status
        );

        app(ServiceWorkManager::class)->startInternal(
            $fixture['work']->id,
            'service:http-parts:direct:start',
            $fixture['operator']
        );

        $line = $purchase->lines->sole();

        $this->actingAs($fixture['operator'])
            ->post(route(
                'service-orders.part-requirements.consume.store',
                [$fixture['order'], $requirement]
            ), [
                'quantity' => (string) $requirement->required_quantity,
                'source_location_id' => null,
                'service_part_purchase_line_id' => $line->id,
                'idempotency_key' => 'service-ui:part-consumption:'.Str::uuid(),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $consumption = ServicePartConsumption::query()->sole();

        $this->assertSame(
            $line->id,
            $consumption->service_part_purchase_line_id
        );
        $this->assertNull(
            $consumption->inventory_movement_line_id
        );
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_validation_and_nested_guards_reject_bypasses(): void
    {
        $fixture = $this->approvedWork('guards-a');
        $other = $this->approvedWork('guards-b');
        $product = $this->product(
            'Repuesto guardas HTTP',
            'PART-HTTP-GUARDS'
        );
        $foreignOrganization = $this->newOrganization(
            'Repuestos ajenos HTTP'
        );
        $foreignLocation = InventoryLocation::query()->create([
            'organization_id' => $foreignOrganization->id,
            'name' => 'Depósito ajeno',
            'type' => InventoryLocationType::Warehouse,
            'active' => true,
        ]);
        $quoteLine = $fixture['option']->lines->first(
            fn ($line): bool => $line->line_type === ServiceQuoteLineType::Part
        );

        $this->actingAs($fixture['operator'])
            ->get(route(
                'service-orders.part-requirements.create',
                [$fixture['order'], $other['work']]
            ))
            ->assertNotFound();

        $this->actingAs($fixture['operator'])
            ->post(route(
                'service-orders.part-requirements.store',
                [$fixture['order'], $fixture['work']]
            ), [
                'service_quote_line_id' => $quoteLine->id,
                'catalog_product_id' => $product->id,
                'condition' => InventoryCondition::New->value,
                'source' => ServicePartSource::Stock->value,
                'idempotency_key' => 'service-ui:part-requirement:'.Str::uuid(),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $requirement = ServicePartRequirement::query()
            ->where('service_order_id', $fixture['order']->id)
            ->sole();

        app(ServiceWorkManager::class)->startInternal(
            $fixture['work']->id,
            'service:http-parts:guards:start',
            $fixture['operator']
        );

        $this->actingAs($fixture['operator'])
            ->post(route(
                'service-orders.part-requirements.consume.store',
                [$fixture['order'], $requirement]
            ), [
                'quantity' => '1',
                'source_location_id' => $foreignLocation->id,
                'idempotency_key' => 'service-ui:part-consumption:'.Str::uuid(),
            ])
            ->assertSessionHasErrors('source_location_id');

        $this->assertDatabaseCount('service_part_consumptions', 0);
    }

    public function test_warranty_requirement_uses_corrective_resolution(): void
    {
        $fixture = $this->deliveredWarranty('parts-http');

        $this->actingAs($fixture['operator'])
            ->post(route(
                'service-orders.warranty-claims.store',
                [$fixture['order'], $fixture['warranty']]
            ), $this->claimPayload($fixture))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $claim = ServiceWarrantyClaim::query()->sole();
        $corrective = $claim->correctiveOrder;

        $this->actingAs($fixture['admin'])
            ->post(route(
                'service-orders.warranty-claims.resolution.store',
                [$corrective, $claim]
            ), [
                'outcome' => ServiceWarrantyClaimOutcome::Accepted->value,
                'technical_basis' => 'La falla coincide con la cobertura otorgada.',
                'covered_scope' => 'Módulo correctivo cubierto sin cargo.',
                'excluded_scope' => null,
                'exception_reason' => null,
                'notes' => null,
                'idempotency_key' => 'service-ui:warranty-resolution:'.Str::uuid(),
            ])
            ->assertRedirect(route(
                'service-orders.show',
                $corrective
            ));

        $this->assertSame(
            ServiceWarrantyClaimStatus::InCorrectiveWork,
            $claim->fresh()->status
        );

        $this->actingAs($fixture['operator'])
            ->post(route(
                'service-orders.work-items.store',
                $corrective
            ), [
                'title' => 'Trabajo correctivo con repuesto',
                'description' => 'Reemplazar el módulo cubierto y probar.',
                'execution_mode' => ServiceWorkExecutionMode::Internal->value,
                'assigned_user_id' => $fixture['operator']->id,
                'provider_business_party_id' => null,
                'idempotency_key' => 'service-ui:work-plan:'.Str::uuid(),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $work = ServiceWorkItem::query()
            ->where('service_order_id', $corrective->id)
            ->sole();
        $product = $this->product(
            'Módulo correctivo HTTP',
            'PART-HTTP-WARRANTY'
        );

        $this->actingAs($fixture['operator'])
            ->get(route(
                'service-orders.part-requirements.create',
                [$corrective, $work]
            ))
            ->assertOk()
            ->assertSee('Trabajo correctivo de garantía')
            ->assertSee('Módulo correctivo cubierto');

        $this->actingAs($fixture['operator'])
            ->post(route(
                'service-orders.part-requirements.store',
                [$corrective, $work]
            ), [
                'service_quote_line_id' => null,
                'catalog_product_id' => $product->id,
                'condition' => InventoryCondition::New->value,
                'source' => ServicePartSource::Stock->value,
                'required_quantity' => '1',
                'idempotency_key' => 'service-ui:part-requirement:'.Str::uuid(),
            ])
            ->assertRedirect(route(
                'service-orders.show',
                $corrective
            ))
            ->assertSessionHasNoErrors();

        $requirement = ServicePartRequirement::query()
            ->where('service_order_id', $corrective->id)
            ->sole();

        $this->assertNull($requirement->service_quote_line_id);
        $this->assertSame(
            $claim->resolution->id,
            $requirement->service_warranty_claim_resolution_id
        );
    }

    /**
     * @return array{
     *   organization: Organization,
     *   operator: User,
     *   order: ServiceOrder,
     *   option: ServiceQuoteOption,
     *   work: ServiceWorkItem,
     *   location: InventoryLocation
     * }
     */
    private function approvedWork(string $suffix): array
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
            'Cliente repuestos '.$suffix
        );
        $location = $this->location($organization);
        $order = app(ServiceOrderIntakeManager::class)->create(
            new ServiceOrderIntakeData(
                assetType: ServiceAssetType::MobilePhone,
                brandName: 'Motorola',
                modelName: 'E22i '.$suffix,
                identifiers: [new ServiceAssetIdentifierData(
                    ServiceIdentifierType::Imei,
                    '358888'.str_pad(
                        (string) (
                            abs(crc32($suffix)) % 1000000000
                        ),
                        9,
                        '0',
                        STR_PAD_LEFT
                    )
                )],
                intakeLocationId: $location->id,
                customerReportedIssue: 'Pantalla rota.',
                idempotencyKey: 'service:http-parts:intake:'.$suffix,
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
                summary: 'Módulo dañado.',
                recommendation: 'Reemplazar módulo y probar el equipo.',
                findings: [new ServiceDiagnosticFindingData(
                    ServiceFindingSeverity::Critical,
                    'Pantalla',
                    'El módulo no entrega imagen.'
                )],
                idempotencyKey: 'service:http-parts:diagnostic:'.$suffix
            ),
            $operator
        );
        $quote = $assessment->issueQuote(
            new ServiceQuoteData(
                serviceOrderId: $order->id,
                options: [new ServiceQuoteOptionData(
                    label: 'Reparación aprobada',
                    lines: [
                        new ServiceQuoteLineData(
                            ServiceQuoteLineType::Part,
                            'Módulo compatible',
                            '1',
                            7000000
                        ),
                        new ServiceQuoteLineData(
                            ServiceQuoteLineType::Part,
                            'Adhesivo técnico',
                            '2',
                            500000
                        ),
                        new ServiceQuoteLineData(
                            ServiceQuoteLineType::Labor,
                            'Instalación y pruebas',
                            '1',
                            3000000
                        ),
                    ],
                    recommended: true
                )],
                idempotencyKey: 'service:http-parts:quote:'.$suffix
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
                idempotencyKey: 'service:http-parts:decision:'.$suffix,
                serviceQuoteOptionId: $option->id
            ),
            $operator
        );
        $work = app(ServiceWorkManager::class)->plan(
            new ServiceWorkItemData(
                serviceOrderId: $order->id,
                serviceQuoteOptionId: $option->id,
                title: 'Reemplazar módulo',
                description: 'Instalación propia y prueba funcional.',
                executionMode: ServiceWorkExecutionMode::Internal,
                idempotencyKey: 'service:http-parts:work:'.$suffix,
                assignedUserId: $operator->id
            ),
            $operator
        );

        return [
            'organization' => $organization,
            'operator' => $operator,
            'order' => $order->fresh(),
            'option' => $option,
            'work' => $work,
            'location' => $location,
        ];
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
            'claimed_at' => now()->format('Y-m-d\TH:i'),
            'customer_reference' => 'GAR-PART-HTTP',
            'idempotency_key' => 'service-ui:warranty-claim:'.Str::uuid(),
        ];
    }

    /**
     * @return array{
     *   organization: Organization,
     *   operator: User,
     *   admin: User,
     *   order: ServiceOrder,
     *   customer: BusinessParty,
     *   location: InventoryLocation,
     *   warranty: ServiceWarrantyGrant
     * }
     */
    private function deliveredWarranty(string $suffix): array
    {
        $organization = Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();
        $operator = $this->user(
            $organization,
            UserRole::Operator
        );
        $admin = $this->user(
            $organization,
            UserRole::Admin
        );
        $customer = $this->party(
            $organization,
            'Cliente garantía repuestos '.$suffix
        );
        $location = $this->location($organization);
        $order = app(ServiceOrderIntakeManager::class)->create(
            new ServiceOrderIntakeData(
                assetType: ServiceAssetType::MobilePhone,
                brandName: 'Motorola',
                modelName: 'E22i garantía',
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
                idempotencyKey: 'service:http-parts-warranty:intake:'.$suffix,
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
                idempotencyKey: 'service:http-parts-warranty:diagnostic:'
                    .$suffix
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
                        'Cambio de módulo y saneamiento',
                        '1',
                        4500000
                    )],
                    recommended: true
                )],
                idempotencyKey: 'service:http-parts-warranty:quote:'.$suffix
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
                idempotencyKey: 'service:http-parts-warranty:decision:'
                    .$suffix,
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
                idempotencyKey: 'service:http-parts-warranty:item:'.$suffix,
                assignedUserId: $operator->id
            ),
            $operator
        );
        $workManager->startInternal(
            $work->id,
            'service:http-parts-warranty:start:'.$suffix,
            $operator
        );
        $report = $workManager->report(
            new ServiceWorkReportData(
                serviceWorkItemId: $work->id,
                outcome: ServiceWorkOutcome::Completed,
                resultSummary: 'Equipo reparado y estable.',
                workPerformed: 'Módulo instalado y software saneado.',
                idempotencyKey: 'service:http-parts-warranty:report:'.$suffix,
                warrantyDays: 90,
                warrantyTerms: 'Garantía sobre mano de obra y componentes.'
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
                ],
                conditionNotes: 'Equipo funcional, sin daños nuevos.',
                accessoriesSnapshot: 'Equipo sin accesorios.',
                idempotencyKey: 'service:http-parts-warranty:quality:'
                    .$suffix
            ),
            $operator
        );
        $this->assertSame(
            ServiceQualityOutcome::Approved,
            $inspection->outcome
        );
        $delivery = $completion->deliver(
            new ServiceDeliveryData(
                serviceOrderId: $order->id,
                serviceQualityInspectionId: $inspection->id,
                recipientName: $customer->name,
                conditionNotes: 'Equipo probado y sin daños nuevos.',
                accessoriesSnapshot: 'Equipo sin accesorios.',
                customerConformity: true,
                idempotencyKey: 'service:http-parts-warranty:delivery:'
                    .$suffix,
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
            'customer' => $customer,
            'location' => $location,
            'warranty' => $warranty,
        ];
    }

    private function seedStock(
        User $actor,
        CatalogProduct $product,
        InventoryLocation $location,
        string $quantity
    ): void {
        $movement = app(InventoryMovementCreator::class)->create(
            new InventoryMovementDraftData(
                type: InventoryMovementType::Receipt,
                effectiveAt: CarbonImmutable::now(),
                reason: 'Ingreso previo del repuesto al stock.',
                idempotencyKey: 'service:http-parts:stock:seed:'.$product->id,
                lines: [new InventoryMovementLineData(
                    catalogProductId: $product->id,
                    condition: InventoryCondition::New,
                    enteredQuantity: $quantity,
                    enteredUnitCode: $product->base_unit_code,
                    destinationLocationId: $location->id
                )]
            ),
            $actor
        );

        app(InventoryMovementConfirmer::class)
            ->confirm($movement, $actor);
    }

    private function product(
        string $name,
        string $sku
    ): CatalogProduct {
        $category = ProductCategory::withoutEvents(
            fn () => ProductCategory::query()->firstOrCreate(
                ['slug' => 'service-parts-http'],
                [
                    'name' => 'Repuestos de servicio HTTP',
                    'active' => true,
                ]
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

    private function supplier(
        Organization $organization,
        string $name
    ): Supplier {
        return Supplier::query()->create([
            'organization_id' => $organization->id,
            'business_party_id' => $this->party(
                $organization,
                $name
            )->id,
            'active' => true,
        ])->load('party');
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

    private function newOrganization(string $name): Organization
    {
        return Organization::query()->create([
            'name' => $name,
            'slug' => Str::slug($name)
                .'-'
                .Str::lower(Str::random(6)),
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
