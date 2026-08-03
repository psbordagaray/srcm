<?php

namespace Tests\Feature\Service;

use App\Domain\Inventory\InventoryMovementConfirmer;
use App\Domain\Inventory\InventoryMovementCreator;
use App\Domain\Inventory\InventoryMovementDraftData;
use App\Domain\Inventory\InventoryMovementLineData;
use App\Domain\Service\ServiceAssessmentManager;
use App\Domain\Service\ServiceAssetIdentifierData;
use App\Domain\Service\ServiceDiagnosticData;
use App\Domain\Service\ServiceDiagnosticFindingData;
use App\Domain\Service\ServiceOrderIntakeData;
use App\Domain\Service\ServiceOrderIntakeManager;
use App\Domain\Service\ServicePartConsumptionData;
use App\Domain\Service\ServicePartManager;
use App\Domain\Service\ServicePartPurchaseData;
use App\Domain\Service\ServicePartPurchaseLineData;
use App\Domain\Service\ServicePartRequirementData;
use App\Domain\Service\ServiceQuoteData;
use App\Domain\Service\ServiceQuoteDecisionData;
use App\Domain\Service\ServiceQuoteLineData;
use App\Domain\Service\ServiceQuoteOptionData;
use App\Domain\Service\ServiceWorkItemData;
use App\Domain\Service\ServiceWorkManager;
use App\Domain\Service\ServiceWorkReportData;
use App\Enums\InventoryCondition;
use App\Enums\InventoryMovementStatus;
use App\Enums\InventoryMovementType;
use App\Enums\ServiceAssetType;
use App\Enums\ServiceFindingSeverity;
use App\Enums\ServiceIdentifierType;
use App\Enums\ServiceOrderStatus;
use App\Enums\ServicePartSource;
use App\Enums\ServiceQuoteDecisionType;
use App\Enums\ServiceQuoteLineType;
use App\Enums\ServiceWorkExecutionMode;
use App\Enums\ServiceWorkOutcome;
use App\Enums\UserRole;
use App\Models\BusinessParty;
use App\Models\CatalogProduct;
use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductCategory;
use App\Models\ServiceOrder;
use App\Models\ServiceQuoteOption;
use App\Models\ServiceWorkItem;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ServicePartFulfillmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_schema_and_part_permissions_are_explicit(): void
    {
        $this->assertTrue(Schema::hasColumns('service_part_requirements', [
            'organization_id',
            'service_order_id',
            'service_work_item_id',
            'service_quote_line_id',
            'catalog_product_id',
            'source',
            'required_quantity',
        ]));
        $this->assertTrue(Schema::hasTable('service_part_purchases'));
        $this->assertTrue(Schema::hasTable('service_part_purchase_lines'));
        $this->assertTrue(Schema::hasTable('service_part_consumptions'));

        $this->assertTrue(UserRole::Admin->canPlanServiceParts());
        $this->assertTrue(UserRole::Operator->canPlanServiceParts());
        $this->assertFalse(UserRole::Viewer->canPlanServiceParts());
        $this->assertTrue(
            UserRole::Operator->canRecordServicePartPurchases()
        );
        $this->assertFalse(
            UserRole::Viewer->canRecordServicePartPurchases()
        );
        $this->assertTrue(UserRole::Operator->canConsumeServiceParts());
        $this->assertFalse(UserRole::Viewer->canConsumeServiceParts());
    }

    public function test_direct_purchase_is_allocated_without_inflating_stock(): void
    {
        [$organization, $actor, $order, $option, $work] =
            $this->approvedWork('direct');
        $products = [
            $this->product('Módulo Motorola E22i', 'PART-DIRECT-1'),
            $this->product('Adhesivo de módulo', 'PART-DIRECT-2'),
        ];
        $partManager = app(ServicePartManager::class);
        $requirements = [];

        foreach ($option->lines->filter(
            fn ($line) =>
                $line->line_type === ServiceQuoteLineType::Part
        )->values() as $index => $quoteLine) {
            $requirements[] = $partManager->plan(
                new ServicePartRequirementData(
                    serviceWorkItemId: $work->id,
                    serviceQuoteLineId: $quoteLine->id,
                    catalogProductId: $products[$index]->id,
                    condition: InventoryCondition::New,
                    source: ServicePartSource::DirectPurchase,
                    requiredQuantity: (string) $quoteLine->quantity,
                    idempotencyKey: 'service:part:direct:req:'.$index
                ),
                $actor
            );
        }

        $this->assertSame(
            ServiceOrderStatus::AwaitingParts,
            $order->fresh()->status
        );

        $supplier = $this->supplier($organization, 'Daniel de Word Cell');
        $purchaseData = new ServicePartPurchaseData(
            serviceOrderId: $order->id,
            supplierId: $supplier->id,
            currencyCode: 'ARS',
            purchasedAt: CarbonImmutable::now(),
            lines: [
                new ServicePartPurchaseLineData(
                    $requirements[0]->id,
                    '1',
                    5000000
                ),
                new ServicePartPurchaseLineData(
                    $requirements[1]->id,
                    '2',
                    500000
                ),
            ],
            idempotencyKey: 'service:part:direct:purchase:1',
            logisticsCostMinor: 100000,
            documentReference: 'WORD-CELL-2026-0802',
            notes: 'Retiro por mensajería local.'
        );
        $purchase = $partManager->recordPurchase($purchaseData, $actor);
        $retry = $partManager->recordPurchase($purchaseData, $actor);

        $this->assertSame($purchase->id, $retry->id);
        $this->assertSame(6000000, $purchase->parts_total_minor);
        $this->assertSame(100000, $purchase->logistics_cost_minor);
        $this->assertSame(6100000, $purchase->grand_total_minor);
        $this->assertCount(2, $purchase->lines);
        $this->assertSame(
            ServiceOrderStatus::InProgress,
            $order->fresh()->status
        );

        app(ServiceWorkManager::class)->startInternal(
            $work->id,
            'service:part:direct:start',
            $actor
        );

        foreach ($purchase->lines as $index => $line) {
            $consumption = $partManager->consume(
                new ServicePartConsumptionData(
                    servicePartRequirementId:
                        $line->service_part_requirement_id,
                    quantity: (string) $line->quantity,
                    idempotencyKey:
                        'service:part:direct:consume:'.$index,
                    servicePartPurchaseLineId: $line->id
                ),
                $actor
            );

            $this->assertSame($line->id, $consumption->purchaseLine->id);
            $this->assertNull($consumption->inventory_movement_line_id);
        }

        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('service_part_consumptions', 2);

        app(ServiceWorkManager::class)->report(
            $this->completedReport($work, 'service:part:direct:report'),
            $actor
        );
        $this->assertSame(
            ServiceOrderStatus::QualityControl,
            $order->fresh()->status
        );
    }

    public function test_stock_part_creates_confirmed_issue_and_reduces_balance(): void
    {
        [$organization, $actor, $order, $option, $work] =
            $this->approvedWork('stock');
        $product = $this->product('SSD 480 GB', 'PART-STOCK-1');
        $location = $this->location($organization);
        $this->seedStock($actor, $product, $location, '2');
        $quoteLine = $option->lines->first(
            fn ($line) =>
                $line->line_type === ServiceQuoteLineType::Part
        );
        $partManager = app(ServicePartManager::class);
        $requirement = $partManager->plan(
            new ServicePartRequirementData(
                serviceWorkItemId: $work->id,
                serviceQuoteLineId: $quoteLine->id,
                catalogProductId: $product->id,
                condition: InventoryCondition::New,
                source: ServicePartSource::Stock,
                requiredQuantity: (string) $quoteLine->quantity,
                idempotencyKey: 'service:part:stock:req:1'
            ),
            $actor
        );

        $this->assertSame(
            ServiceOrderStatus::InProgress,
            $order->fresh()->status
        );
        app(ServiceWorkManager::class)->startInternal(
            $work->id,
            'service:part:stock:start',
            $actor
        );
        $data = new ServicePartConsumptionData(
            servicePartRequirementId: $requirement->id,
            quantity: '1',
            idempotencyKey: 'service:part:stock:consume:1',
            sourceLocationId: $location->id
        );
        $consumption = $partManager->consume($data, $actor);
        $retry = $partManager->consume($data, $actor);

        $this->assertSame($consumption->id, $retry->id);
        $this->assertNotNull($consumption->inventory_movement_line_id);
        $movement = $consumption->inventoryMovementLine->movement;
        $this->assertSame(InventoryMovementType::Issue, $movement->type);
        $this->assertSame(
            InventoryMovementStatus::Confirmed,
            $movement->status
        );
        $this->assertSame('service_order', $movement->source_type);
        $this->assertSame((string) $order->id, $movement->source_id);
        $this->assertSame(
            '1.000000',
            InventoryBalance::query()
                ->where('organization_id', $organization->id)
                ->where('catalog_product_id', $product->id)
                ->where('inventory_location_id', $location->id)
                ->where('condition', InventoryCondition::New->value)
                ->value('quantity')
        );
    }

    public function test_insufficient_stock_rolls_back_service_consumption(): void
    {
        [$organization, $actor, , $option, $work] =
            $this->approvedWork('insufficient');
        $product = $this->product('Teclado notebook', 'PART-NO-STOCK');
        $location = $this->location($organization);
        $quoteLine = $option->lines->first(
            fn ($line) =>
                $line->line_type === ServiceQuoteLineType::Part
        );
        $partManager = app(ServicePartManager::class);
        $requirement = $partManager->plan(
            new ServicePartRequirementData(
                serviceWorkItemId: $work->id,
                serviceQuoteLineId: $quoteLine->id,
                catalogProductId: $product->id,
                condition: InventoryCondition::New,
                source: ServicePartSource::Stock,
                requiredQuantity: '1',
                idempotencyKey: 'service:part:no-stock:req'
            ),
            $actor
        );
        app(ServiceWorkManager::class)->startInternal(
            $work->id,
            'service:part:no-stock:start',
            $actor
        );

        $this->assertDomainFailure(
            fn () => $partManager->consume(
                new ServicePartConsumptionData(
                    servicePartRequirementId: $requirement->id,
                    quantity: '1',
                    idempotencyKey: 'service:part:no-stock:consume',
                    sourceLocationId: $location->id
                ),
                $actor
            )
        );

        $this->assertDatabaseCount('service_part_consumptions', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_work_cannot_complete_before_required_part_is_consumed(): void
    {
        [, $actor, , $option, $work] = $this->approvedWork('completion');
        $product = $this->product('Pantalla requerida', 'PART-BLOCK-REPORT');
        $quoteLine = $option->lines->first(
            fn ($line) =>
                $line->line_type === ServiceQuoteLineType::Part
        );
        app(ServicePartManager::class)->plan(
            new ServicePartRequirementData(
                serviceWorkItemId: $work->id,
                serviceQuoteLineId: $quoteLine->id,
                catalogProductId: $product->id,
                condition: InventoryCondition::New,
                source: ServicePartSource::Stock,
                requiredQuantity: '1',
                idempotencyKey: 'service:part:block:req'
            ),
            $actor
        );
        app(ServiceWorkManager::class)->startInternal(
            $work->id,
            'service:part:block:start',
            $actor
        );

        $this->assertDomainFailure(
            fn () => app(ServiceWorkManager::class)->report(
                $this->completedReport($work, 'service:part:block:report'),
                $actor
            )
        );
        $this->assertDatabaseCount('service_work_reports', 0);

        $this->assertQueryRejected(
            fn () => DB::table('service_work_reports')->insert([
                'organization_id' => $work->organization_id,
                'service_work_item_id' => $work->id,
                'outcome' => ServiceWorkOutcome::Completed->value,
                'result_summary' => 'Intento directo.',
                'work_performed' => 'Intento directo.',
                'recorded_by_user_id' => $actor->id,
                'recorded_at' => now(),
                'idempotency_key' => 'service:part:block:db',
                'fingerprint' => hash('sha256', 'invalid'),
                'created_at' => now(),
                'updated_at' => now(),
            ])
        );
    }

    public function test_viewer_and_database_guards_reject_bypasses(): void
    {
        [$organization, $actor, $order, $option, $work] =
            $this->approvedWork('guards');
        $viewer = $this->user($organization, UserRole::Viewer);
        $product = $this->product('Repuesto protegido', 'PART-GUARD');
        $quoteLine = $option->lines->first(
            fn ($line) =>
                $line->line_type === ServiceQuoteLineType::Part
        );
        $data = new ServicePartRequirementData(
            serviceWorkItemId: $work->id,
            serviceQuoteLineId: $quoteLine->id,
            catalogProductId: $product->id,
            condition: InventoryCondition::New,
            source: ServicePartSource::Stock,
            requiredQuantity: '1',
            idempotencyKey: 'service:part:guard:req'
        );

        $this->assertDomainFailure(
            fn () => app(ServicePartManager::class)->plan($data, $viewer)
        );
        $requirement = app(ServicePartManager::class)->plan($data, $actor);

        $this->assertQueryRejected(
            fn () => DB::table('service_part_requirements')
                ->where('id', $requirement->id)
                ->update(['source' => ServicePartSource::DirectPurchase->value])
        );
        $this->assertQueryRejected(
            fn () => DB::table('service_part_requirements')
                ->where('id', $requirement->id)
                ->delete()
        );
        $this->assertQueryRejected(
            fn () => DB::table('catalog_products')
                ->where('id', $product->id)
                ->update([
                    'base_unit_code' => 'm',
                    'quantity_scale' => 2,
                ])
        );
        $this->assertSame(
            ServiceOrderStatus::InProgress,
            $order->fresh()->status
        );
    }

    /**
     * @return array{
     *     Organization,
     *     User,
     *     ServiceOrder,
     *     ServiceQuoteOption,
     *     ServiceWorkItem
     * }
     */
    private function approvedWork(string $suffix): array
    {
        $organization = Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();
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
                    '359999'.str_pad(
                        (string) (abs(crc32($suffix)) % 1000000000),
                        9,
                        '0',
                        STR_PAD_LEFT
                    )
                )],
                intakeLocationId: $location->id,
                customerReportedIssue: 'Pantalla rota.',
                idempotencyKey: 'service:part:intake:'.$suffix,
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
                summary: 'Módulo dañado.',
                recommendation: 'Reemplazar módulo y probar el equipo.',
                findings: [new ServiceDiagnosticFindingData(
                    ServiceFindingSeverity::Critical,
                    'Pantalla',
                    'El módulo no entrega imagen.'
                )],
                idempotencyKey: 'service:part:diagnostic:'.$suffix
            ),
            $actor
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
                idempotencyKey: 'service:part:quote:'.$suffix
            ),
            $actor
        );
        $option = $quote->options->sole();
        $assessment->recordDecision(
            new ServiceQuoteDecisionData(
                serviceQuoteId: $quote->id,
                decision: ServiceQuoteDecisionType::Approved,
                customerName: 'Cliente '.$suffix,
                channel: 'WhatsApp',
                idempotencyKey: 'service:part:decision:'.$suffix,
                serviceQuoteOptionId: $option->id
            ),
            $actor
        );
        $work = app(ServiceWorkManager::class)->plan(
            new ServiceWorkItemData(
                serviceOrderId: $order->id,
                serviceQuoteOptionId: $option->id,
                title: 'Reemplazar módulo',
                description: 'Instalación propia y prueba funcional.',
                executionMode: ServiceWorkExecutionMode::Internal,
                idempotencyKey: 'service:part:work:'.$suffix,
                assignedUserId: $actor->id
            ),
            $actor
        );

        return [$organization, $actor, $order, $option, $work];
    }

    private function completedReport(
        ServiceWorkItem $work,
        string $key
    ): ServiceWorkReportData {
        return new ServiceWorkReportData(
            serviceWorkItemId: $work->id,
            outcome: ServiceWorkOutcome::Completed,
            resultSummary: 'Reparación completada y verificada.',
            workPerformed: 'Repuesto instalado y equipo probado.',
            idempotencyKey: $key,
            warrantyDays: 90,
            warrantyTerms: 'Garantía sobre repuesto e instalación.'
        );
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
                idempotencyKey: 'service:part:stock:seed:'.$product->id,
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
                ['slug' => 'service-parts'],
                ['name' => 'Repuestos de servicio', 'active' => true]
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
        ]);
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
