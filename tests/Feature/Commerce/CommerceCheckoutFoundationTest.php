<?php

namespace Tests\Feature\Commerce;

use App\Domain\Commerce\CommerceCheckoutData;
use App\Domain\Commerce\CommerceCheckoutManager;
use App\Domain\Commerce\CommercePaymentData;
use App\Domain\Commerce\CommerceProductLineData;
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
use App\Enums\CommerceSaleStatus;
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
use App\Models\BusinessParty;
use App\Models\CatalogProduct;
use App\Models\CommerceSale;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductCategory;
use App\Models\ServiceOrder;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CommerceCheckoutFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_schema_and_commerce_permissions_are_explicit(): void
    {
        $this->assertTrue(Schema::hasColumns('commerce_sales', [
            'service_order_id',
            'service_quote_decision_id',
            'service_quote_option_id',
            'inventory_movement_id',
            'service_subtotal_minor',
            'product_subtotal_minor',
            'total_minor',
        ]));
        $this->assertTrue(Schema::hasColumns('commerce_sale_lines', [
            'line_type',
            'service_quote_line_id',
            'catalog_product_id',
            'inventory_movement_line_id',
        ]));
        $this->assertTrue(Schema::hasColumns('commerce_payments', [
            'method',
            'amount_minor',
            'reference',
            'received_by_user_id',
        ]));
        $this->assertTrue(UserRole::Admin->canRecordCommerceSale());
        $this->assertTrue(UserRole::Operator->canRecordCommerceSale());
        $this->assertFalse(UserRole::Viewer->canRecordCommerceSale());
        $this->assertTrue(UserRole::Viewer->canViewCommerceSales());
    }

    public function test_service_checkout_is_derived_from_approved_quote(): void
    {
        [$organization, $actor, $order, $customer] =
            $this->deliveredOrder('service-only');
        $data = new CommerceCheckoutData(
            currencyCode: 'ARS',
            idempotencyKey: 'commerce:checkout:service-only',
            payments: [new CommercePaymentData(
                CommercePaymentMethod::Cash,
                4000000
            )],
            serviceOrderId: $order->id,
            customerBusinessPartyId: $customer->id
        );
        $sale = app(CommerceCheckoutManager::class)->checkout($data, $actor);
        $retry = app(CommerceCheckoutManager::class)->checkout($data, $actor);

        $this->assertSame($sale->id, $retry->id);
        $this->assertSame(CommerceSaleStatus::Confirmed, $sale->status);
        $this->assertSame($organization->id, $sale->organization_id);
        $this->assertSame($order->id, $sale->service_order_id);
        $this->assertSame(4000000, $sale->service_subtotal_minor);
        $this->assertSame(0, $sale->product_subtotal_minor);
        $this->assertSame(4000000, $sale->total_minor);
        $this->assertNull($sale->inventory_movement_id);
        $this->assertCount(2, $sale->lines);
        $this->assertTrue($sale->lines->every(
            fn ($line): bool =>
                $line->line_type === CommerceSaleLineType::Service
                && $line->service_quote_line_id !== null
                && $line->catalog_product_id === null
        ));
        $this->assertSame(4000000, $sale->payments->sum('amount_minor'));
        $this->assertDatabaseCount('commerce_sales', 1);
    }

    public function test_mixed_checkout_adds_products_and_confirms_stock_issue(): void
    {
        [$organization, $actor, $order, $customer, $location] =
            $this->deliveredOrder('mixed');
        $product = $this->product('Auriculares Bluetooth', 'AUR-MIXED');
        $this->seedStock($actor, $product, $location, '3');
        $sale = app(CommerceCheckoutManager::class)->checkout(
            new CommerceCheckoutData(
                currencyCode: 'ARS',
                idempotencyKey: 'commerce:checkout:mixed',
                payments: [
                    new CommercePaymentData(
                        CommercePaymentMethod::Cash,
                        2500000
                    ),
                    new CommercePaymentData(
                        CommercePaymentMethod::BankTransfer,
                        3000000,
                        'TRANSFERENCIA-9981'
                    ),
                ],
                productLines: [new CommerceProductLineData(
                    catalogProductId: $product->id,
                    sourceLocationId: $location->id,
                    condition: InventoryCondition::New,
                    quantity: '2',
                    unitPriceMinor: 750000,
                    description: 'Intento de descripción libre'
                )],
                serviceOrderId: $order->id,
                customerBusinessPartyId: $customer->id
            ),
            $actor
        );
        $productLine = $sale->lines->first(
            fn ($line): bool =>
                $line->line_type === CommerceSaleLineType::Product
        );

        $this->assertSame(4000000, $sale->service_subtotal_minor);
        $this->assertSame(1500000, $sale->product_subtotal_minor);
        $this->assertSame(5500000, $sale->total_minor);
        $this->assertNotNull($sale->inventory_movement_id);
        $this->assertSame(
            InventoryMovementStatus::Confirmed,
            $sale->inventoryMovement->status
        );
        $this->assertSame(
            InventoryMovementType::Issue,
            $sale->inventoryMovement->type
        );
        $this->assertSame($sale->public_id, $sale->inventoryMovement->source_id);
        $this->assertSame('Auriculares Bluetooth', $productLine->description);
        $this->assertSame($product->id, $productLine->catalog_product_id);
        $this->assertNotNull($productLine->inventory_movement_line_id);
        $this->assertCount(3, $sale->lines);
        $this->assertCount(2, $sale->payments);
        $this->assertSame(5500000, $sale->payments->sum('amount_minor'));
        $this->assertSame($organization->id, $sale->organization_id);
    }

    public function test_product_only_sale_is_supported_for_retail_customer(): void
    {
        $organization = $this->organization();
        $actor = $this->user($organization, UserRole::Operator);
        $customer = $this->party($organization, 'Cliente minorista');
        $location = $this->location($organization);
        $product = $this->product('Funda Motorola E22i', 'FUN-E22I');
        $this->seedStock($actor, $product, $location, '2');
        $sale = app(CommerceCheckoutManager::class)->checkout(
            new CommerceCheckoutData(
                currencyCode: 'ARS',
                idempotencyKey: 'commerce:checkout:retail',
                payments: [new CommercePaymentData(
                    CommercePaymentMethod::DigitalWallet,
                    900000,
                    'MP-441122'
                )],
                productLines: [new CommerceProductLineData(
                    $product->id,
                    $location->id,
                    InventoryCondition::New,
                    '1',
                    900000
                )],
                customerBusinessPartyId: $customer->id
            ),
            $actor
        );

        $this->assertNull($sale->service_order_id);
        $this->assertSame(0, $sale->service_subtotal_minor);
        $this->assertSame(900000, $sale->product_subtotal_minor);
        $this->assertSame($customer->name, $sale->customer_name_snapshot);
        $this->assertCount(1, $sale->lines);
    }

    public function test_delivered_service_cannot_be_disguised_as_retail_sale(): void
    {
        [$organization, $actor, $order, $customer, $location] =
            $this->deliveredOrder('anti-substitution');
        $product = $this->product('Auriculares tentación', 'AUR-FRAUD');
        $this->seedStock($actor, $product, $location, '1');

        $this->assertDomainFailure(
            fn () => app(CommerceCheckoutManager::class)->checkout(
                new CommerceCheckoutData(
                    currencyCode: 'ARS',
                    idempotencyKey: 'commerce:checkout:fraud',
                    payments: [new CommercePaymentData(
                        CommercePaymentMethod::Cash,
                        4000000
                    )],
                    productLines: [new CommerceProductLineData(
                        $product->id,
                        $location->id,
                        InventoryCondition::New,
                        '1',
                        4000000,
                        'Limpieza de virus'
                    )],
                    customerBusinessPartyId: $customer->id
                ),
                $actor
            )
        );

        $this->assertSame(1, ServiceOrder::query()
            ->forOrganization($organization->id)
            ->unsettledDelivered()
            ->whereKey($order->id)
            ->count());
        $this->assertDatabaseCount('commerce_sales', 0);
        $this->assertDatabaseCount('commerce_sale_lines', 0);
    }

    public function test_payment_stock_and_role_failures_are_atomic(): void
    {
        $organization = $this->organization();
        $actor = $this->user($organization, UserRole::Operator);
        $viewer = $this->user($organization, UserRole::Viewer);
        $location = $this->location($organization);
        $product = $this->product('Glass universal', 'GLASS-ATOMIC');

        $invalidPayment = new CommerceCheckoutData(
            currencyCode: 'ARS',
            idempotencyKey: 'commerce:checkout:bad-payment',
            payments: [new CommercePaymentData(
                CommercePaymentMethod::Cash,
                900000
            )],
            productLines: [new CommerceProductLineData(
                $product->id,
                $location->id,
                InventoryCondition::New,
                '1',
                1000000
            )]
        );
        $this->assertDomainFailure(
            fn () => app(CommerceCheckoutManager::class)->checkout(
                $invalidPayment,
                $actor
            )
        );
        $this->assertDomainFailure(
            fn () => app(CommerceCheckoutManager::class)->checkout(
                new CommerceCheckoutData(
                    currencyCode: 'ARS',
                    idempotencyKey: 'commerce:checkout:no-stock',
                    payments: [new CommercePaymentData(
                        CommercePaymentMethod::Cash,
                        1000000
                    )],
                    productLines: [new CommerceProductLineData(
                        $product->id,
                        $location->id,
                        InventoryCondition::New,
                        '1',
                        1000000
                    )]
                ),
                $actor
            )
        );
        $this->assertDomainFailure(
            fn () => app(CommerceCheckoutManager::class)->checkout(
                $invalidPayment,
                $viewer
            )
        );

        $this->assertDatabaseCount('commerce_sales', 0);
        $this->assertDatabaseCount('commerce_sale_lines', 0);
        $this->assertDatabaseCount('commerce_payments', 0);
    }

    public function test_database_guards_reject_commercial_tampering(): void
    {
        [$organization, $actor, $order, $customer] =
            $this->deliveredOrder('database');
        $sale = app(CommerceCheckoutManager::class)->checkout(
            new CommerceCheckoutData(
                currencyCode: 'ARS',
                idempotencyKey: 'commerce:checkout:database',
                payments: [new CommercePaymentData(
                    CommercePaymentMethod::Cash,
                    4000000
                )],
                serviceOrderId: $order->id,
                customerBusinessPartyId: $customer->id
            ),
            $actor
        );

        $this->assertQueryRejected(
            fn () => DB::table('commerce_sales')
                ->where('id', $sale->id)
                ->update(['total_minor' => 1])
        );
        $this->assertQueryRejected(
            fn () => DB::table('commerce_sale_lines')
                ->where('commerce_sale_id', $sale->id)
                ->update(['description' => 'Auriculares'])
        );
        $this->assertQueryRejected(
            fn () => DB::table('commerce_payments')
                ->where('commerce_sale_id', $sale->id)
                ->delete()
        );
        $this->assertQueryRejected(
            fn () => DB::table('commerce_sales')
                ->where('id', $sale->id)
                ->delete()
        );
        $this->assertSame($organization->id, $sale->organization_id);
        $this->assertDatabaseCount('commerce_sales', 1);
    }

    /** @return array{Organization, User, ServiceOrder, BusinessParty, InventoryLocation} */
    private function deliveredOrder(string $suffix): array
    {
        $organization = $this->organization();
        $actor = $this->user($organization, UserRole::Operator);
        $customer = $this->party($organization, 'Cliente '.$suffix);
        $location = $this->location($organization);
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
                customerReportedIssue: 'Pantalla rota y software malicioso.',
                idempotencyKey: 'commerce:intake:'.$suffix,
                customerBusinessPartyId: $customer->id
            ),
            $actor
        );
        $assessment = app(ServiceAssessmentManager::class);
        $assessment->recordDiagnostic(
            new ServiceDiagnosticData(
                serviceOrderId: $order->id,
                summary: 'Módulo dañado y publicidad intrusiva.',
                recommendation: 'Reemplazar módulo y sanear software.',
                findings: [new ServiceDiagnosticFindingData(
                    ServiceFindingSeverity::Critical,
                    'Pantalla',
                    'El módulo debe reemplazarse.'
                )],
                idempotencyKey: 'commerce:diagnostic:'.$suffix
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
                idempotencyKey: 'commerce:quote:'.$suffix
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
                idempotencyKey: 'commerce:decision:'.$suffix,
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
                idempotencyKey: 'commerce:work:'.$suffix,
                assignedUserId: $actor->id
            ),
            $actor
        );
        $workManager->startInternal(
            $work->id,
            'commerce:work:start:'.$suffix,
            $actor
        );
        $workManager->report(
            new ServiceWorkReportData(
                serviceWorkItemId: $work->id,
                outcome: ServiceWorkOutcome::Completed,
                resultSummary: 'Equipo reparado.',
                workPerformed: 'Módulo instalado y software saneado.',
                idempotencyKey: 'commerce:report:'.$suffix,
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
                    new ServiceQualityCheckData('display', 'Pantalla', true),
                    new ServiceQualityCheckData('software', 'Software', true),
                ],
                conditionNotes: 'Equipo funcional.',
                accessoriesSnapshot: 'Equipo sin accesorios.',
                idempotencyKey: 'commerce:quality:'.$suffix
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
                idempotencyKey: 'commerce:delivery:'.$suffix,
                recipientBusinessPartyId: $customer->id
            ),
            $actor
        );

        $this->assertSame(
            ServiceOrderStatus::Delivered,
            $order->fresh()->status
        );

        return [$organization, $actor, $order, $customer, $location];
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
                reason: 'Ingreso previo para prueba comercial.',
                idempotencyKey: 'commerce:stock:'.$product->id,
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
        app(InventoryMovementConfirmer::class)->confirm($movement, $actor);
    }

    private function product(string $name, string $sku): CatalogProduct
    {
        $category = ProductCategory::withoutEvents(
            fn () => ProductCategory::query()->firstOrCreate(
                ['slug' => 'commerce-tests'],
                ['name' => 'Ventas de prueba', 'active' => true]
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

    private function location(Organization $organization): InventoryLocation
    {
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

    private function user(Organization $organization, UserRole $role): User
    {
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
