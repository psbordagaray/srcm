<?php

namespace Tests\Feature\Dashboard;

use App\Domain\Commerce\CommerceCheckoutData;
use App\Domain\Commerce\CommerceCheckoutManager;
use App\Domain\Commerce\CommercePaymentData;
use App\Domain\Commerce\CommerceProductLineData;
use App\Domain\Inventory\InventoryMovementConfirmer;
use App\Domain\Inventory\InventoryMovementCreator;
use App\Domain\Inventory\InventoryMovementDraftData;
use App\Domain\Inventory\InventoryMovementLineData;
use App\Domain\Purchase\PurchaseOrderDraftData;
use App\Domain\Purchase\PurchaseOrderLineData;
use App\Domain\Purchase\PurchaseOrderManager;
use App\Enums\CommercePaymentMethod;
use App\Enums\InventoryCondition;
use App\Enums\InventoryLocationType;
use App\Enums\InventoryMovementType;
use App\Enums\ServiceAssetType;
use App\Enums\ServiceOrderStatus;
use App\Enums\UserRole;
use App\Http\Controllers\DashboardController;
use App\Http\Middleware\RequireOrganization;
use App\Models\BusinessParty;
use App\Models\CatalogProduct;
use App\Models\CommerceSale;
use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductCategory;
use App\Models\PurchaseOrder;
use App\Models\ServiceAsset;
use App\Models\ServiceOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardOperationalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        Carbon::setTestNow(
            Carbon::parse('2026-08-06 20:30:00')
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_dashboard_route_is_scoped_and_no_longer_a_placeholder(): void
    {
        $organization = $this->organization();
        $viewer = $this->user($organization, UserRole::Viewer);
        $route = app('router')->getRoutes()->getByName('dashboard');

        $this->assertNotNull($route);
        $this->assertSame(
            DashboardController::class.'@index',
            $route->getActionName()
        );
        $this->assertContains(
            RequireOrganization::class,
            $route->gatherMiddleware()
        );

        $this->actingAs($viewer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Panel operativo')
            ->assertSee('Reparaciones abiertas')
            ->assertSee('Compras por recibir')
            ->assertSee('Ventas confirmadas hoy')
            ->assertSee('Posiciones con disponibilidad')
            ->assertDontSee('Catálogo pendiente')
            ->assertDontSee('Búsqueda inteligente')
            ->assertDontSee('El futuro corazón de SRCM.');
    }

    public function test_dashboard_uses_real_scoped_operational_metrics(): void
    {
        $organization = $this->organization();
        $foreign = $this->newOrganization('Dashboard externo');
        $actor = $this->user($organization, UserRole::Operator);
        $foreignActor = $this->user($foreign, UserRole::Operator);

        $customer = $this->party($organization, 'Cliente Dashboard Local');
        $foreignCustomer = $this->party(
            $foreign,
            'Cliente Dashboard SECRETO'
        );

        $localLocation = $this->location($organization);
        $foreignLocation = $this->newLocation(
            $foreign,
            'Depósito SECRETO'
        );
        $product = $this->product('Producto Dashboard', 'DASH-001');

        $this->balance(
            $organization,
            $product,
            $localLocation,
            '3.000000'
        );
        $this->balance(
            $foreign,
            $product,
            $foreignLocation,
            '999.000000'
        );

        $this->serviceOrder(
            $organization,
            $actor,
            $customer,
            $localLocation,
            7001
        );
        $this->serviceOrder(
            $foreign,
            $foreignActor,
            $foreignCustomer,
            $foreignLocation,
            9901
        );

        $localSupplier = $this->supplier(
            $organization,
            'Proveedor Dashboard Local'
        );
        $foreignSupplier = $this->supplier(
            $foreign,
            'Proveedor Dashboard SECRETO'
        );

        $this->purchase(
            $organization,
            $actor,
            $localSupplier,
            'local'
        );
        $this->purchase(
            $foreign,
            $foreignActor,
            $foreignSupplier,
            'foreign'
        );

        $this->sale(
            $organization,
            $actor,
            $customer,
            'ARS',
            1250000,
            8001
        );
        $this->sale(
            $foreign,
            $foreignActor,
            $foreignCustomer,
            'ARS',
            99999900,
            9901
        );

        $this->actingAs($actor)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(
                'aria-label="Reparaciones abiertas: 1"',
                false
            )
            ->assertSee(
                'aria-label="Compras por recibir: 1"',
                false
            )
            ->assertSee(
                'aria-label="Ventas confirmadas hoy: 1"',
                false
            )
            ->assertSee(
                'aria-label="Posiciones con disponibilidad: 1"',
                false
            )
            ->assertSee('Cliente Dashboard Local')
            ->assertSee('Proveedor Dashboard Local')
            ->assertSee('ARS 12.500,00')
            ->assertDontSee('SECRETO')
            ->assertDontSee('ARS 999.999,00');
    }

    public function test_sales_today_are_grouped_by_currency(): void
    {
        $organization = $this->organization();
        $actor = $this->user($organization, UserRole::Operator);
        $customer = $this->party(
            $organization,
            'Cliente Multimoneda Dashboard'
        );

        $this->sale(
            $organization,
            $actor,
            $customer,
            'ARS',
            1200000,
            8101
        );
        $this->sale(
            $organization,
            $actor,
            $customer,
            'USD',
            5000,
            8102
        );

        $this->actingAs($actor)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(
                'aria-label="Ventas confirmadas hoy: 2"',
                false
            )
            ->assertSee('ARS 12.000,00')
            ->assertSee('USD 50,00');
    }

    public function test_viewer_keeps_read_access_without_mutating_quick_actions(): void
    {
        $organization = $this->organization();
        $viewer = $this->user($organization, UserRole::Viewer);

        $this->actingAs($viewer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('service-orders.index'), false)
            ->assertSee(route('purchase-orders.index'), false)
            ->assertSee(route('commerce-sales.index'), false)
            ->assertDontSee('Nueva reparación')
            ->assertDontSee('Nueva compra')
            ->assertDontSee('Nueva venta');
    }

    private function organization(): Organization
    {
        return Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();
    }

    private function newOrganization(string $name): Organization
    {
        return Organization::withoutEvents(
            fn () => Organization::query()->create([
                'name' => $name,
                'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
                'active' => true,
            ])
        );
    }

    private function user(
        Organization $organization,
        UserRole $role
    ): User {
        $user = User::factory()->create([
            'role' => $role,
            'email_verified_at' => now(),
        ]);

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

        $user->forceFill([
            'current_organization_id' => $organization->id,
        ])->saveQuietly();

        return $user->refresh();
    }

    private function party(
        Organization $organization,
        string $name
    ): BusinessParty {
        return BusinessParty::withoutEvents(
            fn () => BusinessParty::query()->create([
                'organization_id' => $organization->id,
                'party_type' => BusinessParty::TYPE_PERSON,
                'name' => $name,
                'email' => Str::lower(Str::random(10)).'@dashboard.test',
            ])
        );
    }

    private function supplier(
        Organization $organization,
        string $name
    ): Supplier {
        $party = BusinessParty::withoutEvents(
            fn () => BusinessParty::query()->create([
                'organization_id' => $organization->id,
                'party_type' => BusinessParty::TYPE_ORGANIZATION,
                'name' => $name,
                'email' => Str::lower(Str::random(10)).'@supplier.test',
            ])
        );

        return Supplier::withoutEvents(
            fn () => Supplier::query()->create([
                'organization_id' => $organization->id,
                'business_party_id' => $party->id,
                'active' => true,
            ])
        )->load('party');
    }

    private function location(
        Organization $organization
    ): InventoryLocation {
        return InventoryLocation::query()
            ->where('organization_id', $organization->id)
            ->orderBy('id')
            ->firstOrFail();
    }

    private function newLocation(
        Organization $organization,
        string $name
    ): InventoryLocation {
        return InventoryLocation::withoutEvents(
            fn () => InventoryLocation::query()->create([
                'organization_id' => $organization->id,
                'name' => $name,
                'type' => InventoryLocationType::Warehouse,
                'active' => true,
            ])
        );
    }

    private function product(
        string $name,
        string $sku
    ): CatalogProduct {
        $category = ProductCategory::withoutEvents(
            fn () => ProductCategory::query()->firstOrCreate(
                ['slug' => 'dashboard-tests'],
                ['name' => 'Dashboard Tests', 'active' => true]
            )
        );

        return CatalogProduct::withoutEvents(
            fn () => CatalogProduct::query()->create([
                'product_category_id' => $category->id,
                'sku' => $sku,
                'name' => $name,
                'base_unit_code' => 'unit',
                'quantity_scale' => 0,
                'active' => true,
            ])->refresh()
        );
    }

    private function balance(
        Organization $organization,
        CatalogProduct $product,
        InventoryLocation $location,
        string $quantity
    ): InventoryBalance {
        return InventoryBalance::query()->create([
            'organization_id' => $organization->id,
            'catalog_product_id' => $product->id,
            'inventory_location_id' => $location->id,
            'condition' => InventoryCondition::New,
            'quantity' => $quantity,
            'base_unit_code' => $product->base_unit_code,
            'version' => 1,
        ]);
    }

    private function serviceOrder(
        Organization $organization,
        User $actor,
        BusinessParty $customer,
        InventoryLocation $location,
        int $number
    ): ServiceOrder {
        $asset = ServiceAsset::withoutEvents(
            fn () => ServiceAsset::query()->create([
                'organization_id' => $organization->id,
                'public_id' => (string) Str::uuid(),
                'asset_type' => ServiceAssetType::MobilePhone,
                'brand_name' => 'Dashboard',
                'model_name' => 'Equipo '.$number,
                'created_by_user_id' => $actor->id,
            ])
        );

        return ServiceOrder::withoutEvents(
            fn () => ServiceOrder::query()->create([
                'organization_id' => $organization->id,
                'public_id' => (string) Str::uuid(),
                'order_number' => $number,
                'service_asset_id' => $asset->id,
                'customer_business_party_id' => $customer->id,
                'owner_business_party_id' => $customer->id,
                'intake_location_id' => $location->id,
                'status' => ServiceOrderStatus::Received,
                'created_by_user_id' => $actor->id,
                'received_at' => now(),
                'promised_at' => null,
                'idempotency_key' => 'dashboard:service:'.$number,
                'metadata' => [],
            ])
        );
    }

    private function purchase(
        Organization $organization,
        User $actor,
        Supplier $supplier,
        string $suffix
    ): PurchaseOrder {
        $product = $this->product(
            'Producto compra Dashboard '.$suffix.' '.$organization->id,
            'DASH-PURCHASE-'.$organization->id.'-'.Str::upper($suffix)
        );
        $manager = app(PurchaseOrderManager::class);
        $order = $manager->draft(
            new PurchaseOrderDraftData(
                supplierId: $supplier->id,
                currencyCode: 'ARS',
                idempotencyKey: 'dashboard:purchase:'.$organization->id.':'.$suffix,
                lines: [new PurchaseOrderLineData(
                    catalogProductId: $product->id,
                    quantity: '1',
                    unitCostMinor: 100000,
                    description: 'Fixture compra Dashboard'
                )],
                expectedLogisticsCostMinor: 0,
                notes: 'Dashboard '.$suffix
            ),
            $actor
        );

        return $manager->issue($order, $actor);
    }

    private function sale(
        Organization $organization,
        User $actor,
        BusinessParty $customer,
        string $currency,
        int $totalMinor,
        int $number
    ): CommerceSale {
        $product = $this->product(
            'Producto venta Dashboard '.$number,
            'DASH-SALE-'.$number
        );
        $location = $this->location($organization);

        $movement = app(InventoryMovementCreator::class)->create(
            new InventoryMovementDraftData(
                type: InventoryMovementType::Receipt,
                effectiveAt: now(),
                reason: 'Stock de fixture para venta Dashboard.',
                idempotencyKey: 'dashboard:stock:'.$number,
                lines: [new InventoryMovementLineData(
                    catalogProductId: $product->id,
                    condition: InventoryCondition::New,
                    enteredQuantity: '1',
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

        return app(CommerceCheckoutManager::class)->checkout(
            new CommerceCheckoutData(
                currencyCode: $currency,
                idempotencyKey: 'dashboard:sale:'.$number,
                payments: [new CommercePaymentData(
                    CommercePaymentMethod::Cash,
                    $totalMinor
                )],
                productLines: [new CommerceProductLineData(
                    catalogProductId: $product->id,
                    sourceLocationId: $location->id,
                    condition: InventoryCondition::New,
                    quantity: '1',
                    unitPriceMinor: $totalMinor
                )],
                customerBusinessPartyId: $customer->id,
                notes: 'Dashboard',
                soldAt: now()
            ),
            $actor
        );
    }
}
