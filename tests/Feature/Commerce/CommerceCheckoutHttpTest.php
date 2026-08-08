<?php

namespace Tests\Feature\Commerce;

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
use App\Enums\CommercePaymentMethod;
use App\Enums\CommerceSaleLineType;
use App\Enums\InventoryCondition;
use App\Enums\InventoryMovementStatus;
use App\Enums\InventoryMovementType;
use App\Enums\ServiceAssetType;
use App\Enums\ServiceFindingSeverity;
use App\Enums\ServiceIdentifierType;
use App\Enums\ServiceOrderStatus;
use App\Enums\ServiceQuoteDecisionType;
use App\Enums\ServiceQuoteLineType;
use App\Enums\ServiceWorkExecutionMode;
use App\Enums\ServiceWorkOutcome;
use App\Enums\UserRole;
use App\Http\Middleware\RequireOrganization;
use App\Models\BusinessParty;
use App\Models\CatalogProduct;
use App\Models\CommerceSale;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\OrganizationProductPrice;
use App\Models\ProductCategory;
use App\Models\ServiceOrder;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CommerceCheckoutHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_routes_and_commerce_permissions_are_explicit(): void
    {
        $organization = $this->organization();
        $viewer = $this->user($organization, UserRole::Viewer);
        $routes = [
            'commerce-sales.index' => ['GET', 'can:view-commerce-sales'],
            'commerce-sales.show' => ['GET', 'can:view-commerce-sales'],
            'commerce-sales.create' => [
                'GET',
                'can:record-commerce-sales',
            ],
            'commerce-sales.store' => [
                'POST',
                'can:record-commerce-sales',
            ],
        ];

        foreach ($routes as $name => [$method, $ability]) {
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
            ->get(route('commerce-sales.index'))
            ->assertOk()
            ->assertSee('Ventas y cobros');

        $this->actingAs($viewer)
            ->get(route('commerce-sales.create'))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->post(route('commerce-sales.store'), [])
            ->assertForbidden();

        $this->assertDatabaseCount('commerce_sales', 0);
    }

    public function test_operator_liquidates_delivered_service_through_http(): void
    {
        $fixture = $this->deliveredOrder('service-http');

        $this->actingAs($fixture['actor'])
            ->get(route('commerce-sales.create', [
                'service_order' => $fixture['order']->public_id,
            ]))
            ->assertOk()
            ->assertSee('Nueva venta y cobro')
            ->assertSee('Presupuesto aprobado')
            ->assertSee('40.000,00');

        $this->actingAs($fixture['actor'])
            ->post(route('commerce-sales.store'), [
                'currency_code' => 'ARS',
                'service_order_id' => $fixture['order']->id,
                'customer_business_party_id' => $fixture['customer']->id,
                'customer_name' => null,
                'customer_document' => null,
                'product_lines' => [],
                'payments' => [[
                    'method' => CommercePaymentMethod::Cash->value,
                    'amount' => '40000,00',
                    'reference' => null,
                    'notes' => 'Pago total en mostrador.',
                    'paid_at' => null,
                ]],
                'notes' => 'Liquidación de reparación.',
                'sold_at' => null,
                'idempotency_key' => 'service-ui:commerce-sale:'.Str::uuid(),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $sale = CommerceSale::query()
            ->with(['lines', 'payments'])
            ->sole();

        $this->assertSame(
            $fixture['order']->id,
            $sale->service_order_id
        );
        $this->assertSame(4000000, $sale->service_subtotal_minor);
        $this->assertSame(0, $sale->product_subtotal_minor);
        $this->assertSame(4000000, $sale->total_minor);
        $this->assertNull($sale->inventory_movement_id);
        $this->assertCount(2, $sale->lines);
        $this->assertTrue(
            $sale->lines->every(
                fn ($line): bool => $line->line_type
                        === CommerceSaleLineType::Service
            )
        );
        $this->assertSame(
            4000000,
            $sale->payments->sum('amount_minor')
        );

        $this->actingAs($fixture['actor'])
            ->get(route('commerce-sales.show', $sale))
            ->assertOk()
            ->assertSee('Venta #'.$sale->sale_number)
            ->assertSee('Pagos exactos')
            ->assertSee('Orden #'.$fixture['order']->order_number);

        $this->actingAs($fixture['actor'])
            ->get(route(
                'service-orders.show',
                $fixture['order']
            ))
            ->assertOk()
            ->assertSee('Venta y cobro')
            ->assertSee('Venta #'.$sale->sale_number);
    }

    public function test_mixed_sale_confirms_product_issue_and_split_payments(): void
    {
        $fixture = $this->deliveredOrder('mixed-http');
        $product = $this->product(
            'Auriculares Bluetooth HTTP',
            'AUR-HTTP'
        );
        $this->seedStock(
            $fixture['actor'],
            $product,
            $fixture['location'],
            '3'
        );
        $this->setPrice(
            $fixture['organization'],
            $fixture['actor'],
            $product,
            750000
        );

        $this->actingAs($fixture['actor'])
            ->post(route('commerce-sales.store'), [
                'currency_code' => 'ARS',
                'service_order_id' => $fixture['order']->id,
                'customer_business_party_id' => $fixture['customer']->id,
                'product_lines' => [[
                    'catalog_product_id' => $product->id,
                    'source_location_id' => $fixture['location']->id,
                    'condition' => InventoryCondition::New->value,
                    'quantity' => '2',
                    'unit_price' => '7500',
                ]],
                'payments' => [
                    [
                        'method' => CommercePaymentMethod::Cash->value,
                        'amount' => '25000',
                        'reference' => null,
                        'notes' => null,
                        'paid_at' => null,
                    ],
                    [
                        'method' => CommercePaymentMethod::BankTransfer->value,
                        'amount' => '30000',
                        'reference' => 'TRANSFERENCIA-HTTP-9981',
                        'notes' => null,
                        'paid_at' => null,
                    ],
                ],
                'idempotency_key' => 'service-ui:commerce-sale:'.Str::uuid(),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $sale = CommerceSale::query()
            ->with([
                'lines',
                'payments',
                'inventoryMovement.lines',
            ])
            ->sole();

        $this->assertSame(4000000, $sale->service_subtotal_minor);
        $this->assertSame(1500000, $sale->product_subtotal_minor);
        $this->assertSame(5500000, $sale->total_minor);
        $this->assertCount(3, $sale->lines);
        $this->assertCount(2, $sale->payments);
        $this->assertSame(
            InventoryMovementStatus::Confirmed,
            $sale->inventoryMovement->status
        );
        $this->assertSame(
            InventoryMovementType::Issue,
            $sale->inventoryMovement->type
        );
        $this->assertSame(
            $sale->public_id,
            $sale->inventoryMovement->source_id
        );

        $productLine = $sale->lines->first(
            fn ($line): bool => $line->line_type === CommerceSaleLineType::Product
        );

        $this->assertSame($product->id, $productLine->catalog_product_id);
        $this->assertNotNull(
            $productLine->inventory_movement_line_id
        );
    }

    public function test_product_only_sale_and_electronic_reference_validation(): void
    {
        $organization = $this->organization();
        $actor = $this->user(
            $organization,
            UserRole::Operator
        );
        $customer = $this->party(
            $organization,
            'Cliente minorista HTTP'
        );
        $location = $this->location($organization);
        $product = $this->product(
            'Funda Motorola HTTP',
            'FUN-HTTP'
        );
        $this->seedStock($actor, $product, $location, '2');
        $this->setPrice(
            $organization,
            $actor,
            $product,
            900000
        );
        $base = [
            'currency_code' => 'ARS',
            'service_order_id' => null,
            'customer_business_party_id' => $customer->id,
            'product_lines' => [[
                'catalog_product_id' => $product->id,
                'source_location_id' => $location->id,
                'condition' => InventoryCondition::New->value,
                'quantity' => '1',
                'unit_price' => '9000',
            ]],
        ];

        $this->actingAs($actor)
            ->post(route('commerce-sales.store'), [
                ...$base,
                'payments' => [[
                    'method' => CommercePaymentMethod::DigitalWallet->value,
                    'amount' => '9000',
                    'reference' => null,
                    'notes' => null,
                    'paid_at' => null,
                ]],
                'idempotency_key' => 'service-ui:commerce-sale:'.Str::uuid(),
            ])
            ->assertSessionHasErrors('payments.0.reference');

        $this->assertDatabaseCount('commerce_sales', 0);

        $this->actingAs($actor)
            ->post(route('commerce-sales.store'), [
                ...$base,
                'payments' => [[
                    'method' => CommercePaymentMethod::DigitalWallet->value,
                    'amount' => '9000',
                    'reference' => 'MP-HTTP-441122',
                    'notes' => null,
                    'paid_at' => null,
                ]],
                'idempotency_key' => 'service-ui:commerce-sale:'.Str::uuid(),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $sale = CommerceSale::query()->sole();

        $this->assertNull($sale->service_order_id);
        $this->assertSame(0, $sale->service_subtotal_minor);
        $this->assertSame(900000, $sale->product_subtotal_minor);
        $this->assertSame(
            $customer->name,
            $sale->customer_name_snapshot
        );
    }

    public function test_http_guards_reject_foreign_evidence_and_non_exact_payment(): void
    {
        $fixture = $this->deliveredOrder('guards-http');
        $foreign = Organization::query()->create([
            'name' => 'Organización extranjera comercio',
            'slug' => 'organizacion-extranjera-comercio',
            'active' => true,
        ]);
        $foreignCustomer = $this->party(
            $foreign,
            'Cliente extranjero'
        );
        $base = [
            'currency_code' => 'ARS',
            'service_order_id' => $fixture['order']->id,
            'customer_business_party_id' => $fixture['customer']->id,
            'product_lines' => [],
            'payments' => [[
                'method' => CommercePaymentMethod::Cash->value,
                'amount' => '39999',
                'reference' => null,
                'notes' => null,
                'paid_at' => null,
            ]],
        ];

        $this->actingAs($fixture['actor'])
            ->post(route('commerce-sales.store'), [
                ...$base,
                'customer_business_party_id' => $foreignCustomer->id,
                'idempotency_key' => 'service-ui:commerce-sale:'.Str::uuid(),
            ])
            ->assertSessionHasErrors(
                'customer_business_party_id'
            );

        $this->actingAs($fixture['actor'])
            ->post(route('commerce-sales.store'), [
                ...$base,
                'idempotency_key' => 'service-ui:commerce-sale:'.Str::uuid(),
            ])
            ->assertSessionHasErrors('commerce');

        $this->assertDatabaseCount('commerce_sales', 0);
        $this->assertDatabaseCount('commerce_sale_lines', 0);
        $this->assertDatabaseCount('commerce_payments', 0);
        $this->assertSame(
            ServiceOrderStatus::Delivered,
            $fixture['order']->fresh()->status
        );
    }

    /**
     * @return array{
     *   organization: Organization,
     *   actor: User,
     *   order: ServiceOrder,
     *   customer: BusinessParty,
     *   location: InventoryLocation
     * }
     */
    private function deliveredOrder(string $suffix): array
    {
        $organization = $this->organization();
        $actor = $this->user(
            $organization,
            UserRole::Operator
        );
        $customer = $this->party(
            $organization,
            'Cliente '.$suffix
        );
        $location = $this->location($organization);
        $order = app(ServiceOrderIntakeManager::class)->create(
            new ServiceOrderIntakeData(
                assetType: ServiceAssetType::MobilePhone,
                brandName: 'Motorola',
                modelName: 'E22i '.$suffix,
                identifiers: [new ServiceAssetIdentifierData(
                    ServiceIdentifierType::Imei,
                    '359997'.str_pad(
                        (string) (
                            abs(crc32($suffix)) % 1000000000
                        ),
                        9,
                        '0',
                        STR_PAD_LEFT
                    )
                )],
                intakeLocationId: $location->id,
                customerReportedIssue: 'Pantalla rota y software malicioso.',
                idempotencyKey: 'commerce:http:intake:'.$suffix,
                customerBusinessPartyId: $customer->id
            ),
            $actor
        );
        $assessment = app(ServiceAssessmentManager::class);
        $assessment->recordDiagnostic(
            new ServiceDiagnosticData(
                serviceOrderId: $order->id,
                summary: 'Módulo dañado y publicidad intrusiva.',
                recommendation: 'Cambiar módulo y sanear el software.',
                findings: [new ServiceDiagnosticFindingData(
                    ServiceFindingSeverity::Critical,
                    'Pantalla',
                    'El módulo no entrega imagen correctamente.'
                )],
                idempotencyKey: 'commerce:http:diagnostic:'.$suffix
            ),
            $actor
        );
        $quote = $assessment->issueQuote(
            new ServiceQuoteData(
                serviceOrderId: $order->id,
                options: [new ServiceQuoteOptionData(
                    label: 'Solución integral',
                    lines: [
                        new ServiceQuoteLineData(
                            ServiceQuoteLineType::Labor,
                            'Cambio de módulo',
                            '1',
                            2500000
                        ),
                        new ServiceQuoteLineData(
                            ServiceQuoteLineType::DataService,
                            'Limpieza de software malicioso',
                            '1',
                            1500000
                        ),
                    ],
                    recommended: true
                )],
                idempotencyKey: 'commerce:http:quote:'.$suffix
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
                idempotencyKey: 'commerce:http:decision:'.$suffix,
                serviceQuoteOptionId: $option->id
            ),
            $actor
        );
        $workManager = app(ServiceWorkManager::class);
        $work = $workManager->plan(
            new ServiceWorkItemData(
                serviceOrderId: $order->id,
                serviceQuoteOptionId: $option->id,
                title: 'Reparación integral',
                description: 'Cambio de módulo y saneamiento.',
                executionMode: ServiceWorkExecutionMode::Internal,
                idempotencyKey: 'commerce:http:work:'.$suffix,
                assignedUserId: $actor->id
            ),
            $actor
        );
        $workManager->startInternal(
            $work->id,
            'commerce:http:work:start:'.$suffix,
            $actor
        );
        $workManager->report(
            new ServiceWorkReportData(
                serviceWorkItemId: $work->id,
                outcome: ServiceWorkOutcome::Completed,
                resultSummary: 'Equipo reparado.',
                workPerformed: 'Módulo instalado y software saneado.',
                idempotencyKey: 'commerce:http:report:'.$suffix,
                warrantyDays: 90,
                warrantyTerms: 'Garantía integral de reparación.'
            ),
            $actor
        );
        $completion = app(ServiceCompletionManager::class);
        $inspection = $completion->inspect(
            new ServiceQualityInspectionData(
                serviceOrderId: $order->id,
                checks: [
                    new ServiceQualityCheckData(
                        'display',
                        'Pantalla',
                        true
                    ),
                    new ServiceQualityCheckData(
                        'software',
                        'Software',
                        true
                    ),
                ],
                conditionNotes: 'Equipo funcional.',
                accessoriesSnapshot: 'Equipo sin accesorios.',
                idempotencyKey: 'commerce:http:quality:'.$suffix
            ),
            $actor
        );
        $completion->deliver(
            new ServiceDeliveryData(
                serviceOrderId: $order->id,
                serviceQualityInspectionId: $inspection->id,
                recipientName: $customer->name,
                conditionNotes: 'Equipo probado y entregado.',
                accessoriesSnapshot: 'Equipo sin accesorios.',
                customerConformity: true,
                idempotencyKey: 'commerce:http:delivery:'.$suffix,
                recipientBusinessPartyId: $customer->id
            ),
            $actor
        );

        $this->assertSame(
            ServiceOrderStatus::Delivered,
            $order->fresh()->status
        );

        return [
            'organization' => $organization,
            'actor' => $actor,
            'order' => $order->fresh(),
            'customer' => $customer,
            'location' => $location,
        ];
    }

    private function setPrice(
        Organization $organization,
        User $actor,
        CatalogProduct $product,
        int $amountMinor
    ): void {
        OrganizationProductPrice::query()->create([
            'organization_id' => $organization->id,
            'catalog_product_id' => $product->id,
            'currency_code' => 'ARS',
            'amount_minor' => $amountMinor,
            'valid_from' => now()->subSecond(),
            'valid_until' => null,
            'is_current' => true,
            'reason' => 'Fixture HTTP',
            'created_by_user_id' => $actor->id,
        ]);
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
                reason: 'Ingreso previo para venta HTTP.',
                idempotencyKey: 'commerce:http:stock:'.$product->id,
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

        app(InventoryMovementConfirmer::class)->confirm(
            $movement,
            $actor
        );
    }

    private function product(
        string $name,
        string $sku
    ): CatalogProduct {
        $category = ProductCategory::withoutEvents(
            fn () => ProductCategory::query()->firstOrCreate(
                ['slug' => 'commerce-http-tests'],
                [
                    'name' => 'Ventas HTTP de prueba',
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

    private function organization(): Organization
    {
        return Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();
    }

    private function location(
        Organization $organization
    ): InventoryLocation {
        return InventoryLocation::query()
            ->forOrganization($organization->id)
            ->orderBy('id')
            ->firstOrFail();
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
}
