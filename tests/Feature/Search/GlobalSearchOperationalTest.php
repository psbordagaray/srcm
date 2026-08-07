<?php

namespace Tests\Feature\Search;

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
use App\Enums\ServiceIdentifierType;
use App\Enums\ServiceOrderStatus;
use App\Enums\UserRole;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Middleware\RequireOrganization;
use App\Models\Brand;
use App\Models\BusinessParty;
use App\Models\CatalogProduct;
use App\Models\CommerceSale;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductCategory;
use App\Models\PurchaseOrder;
use App\Models\ServiceAsset;
use App\Models\ServiceAssetIdentifier;
use App\Models\ServiceOrder;
use App\Models\Supplier;
use App\Models\TechnicalModel;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class GlobalSearchOperationalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_route_is_scoped_read_only_and_navigation_exposes_search(): void
    {
        $organization = $this->organization();
        $viewer = $this->user($organization, UserRole::Viewer);
        $route = app('router')->getRoutes()
            ->getByName('global-search.index');

        $this->assertNotNull($route);
        $this->assertSame(
            GlobalSearchController::class.'@index',
            $route->getActionName()
        );
        $this->assertContains(
            RequireOrganization::class,
            $route->gatherMiddleware()
        );
        $this->assertSame(['GET', 'HEAD'], $route->methods());

        $this->actingAs($viewer)
            ->get(route('global-search.index'))
            ->assertOk()
            ->assertSee('Búsqueda global')
            ->assertSee('Buscador operativo listo')
            ->assertSee('Buscar');

        $this->actingAs($viewer)
            ->get(route('global-search.index', ['q' => '%%']))
            ->assertOk()
            ->assertSee('Consulta demasiado corta');
    }

    public function test_shared_catalog_finds_product_and_technical_model(): void
    {
        $organization = $this->organization();
        $actor = $this->user($organization, UserRole::Operator);
        $category = $this->category();
        $brand = Brand::query()->create([
            'name' => 'Marca Alfa Search',
            'active' => true,
        ]);

        $product = CatalogProduct::query()->create([
            'product_category_id' => $category->id,
            'brand_id' => $brand->id,
            'sku' => 'ALFA-900',
            'name' => 'Control Alfa Global',
            'base_unit_code' => 'unit',
            'quantity_scale' => 0,
            'active' => true,
        ]);

        $model = TechnicalModel::query()->create([
            'brand_id' => $brand->id,
            'product_category_id' => $category->id,
            'code' => 'ALFA-900-TM',
            'name' => 'Modelo Alfa Técnico',
            'active' => true,
        ]);

        $this->actingAs($actor)
            ->get(route('global-search.index', ['q' => 'ALFA-900']))
            ->assertOk()
            ->assertSee('Productos')
            ->assertSee('Modelos técnicos')
            ->assertSee($product->name)
            ->assertSee($model->name)
            ->assertSee(route('products.show', $product), false)
            ->assertSee(
                route('technical-models.show', $model),
                false
            );
    }

    public function test_private_person_and_service_identifier_are_tenant_scoped(): void
    {
        $local = $this->organization();
        $foreign = $this->newOrganization('Search externa');
        $actor = $this->user($local, UserRole::Operator);
        $foreignActor = $this->user($foreign, UserRole::Operator);

        $localParty = $this->party(
            $local,
            'Cliente Alfa Local'
        );
        $foreignParty = $this->party(
            $foreign,
            'Cliente Alfa SECRETO'
        );

        $localOrder = $this->serviceOrder(
            $local,
            $actor,
            $localParty,
            $this->location($local),
            7101
        );
        $foreignOrder = $this->serviceOrder(
            $foreign,
            $foreignActor,
            $foreignParty,
            $this->newLocation($foreign, 'Depósito externo'),
            9901
        );

        $imei = '356789012345678';
        $this->identifier($localOrder, $actor, $imei);
        $this->identifier($foreignOrder, $foreignActor, $imei);

        $this->actingAs($actor)
            ->get(route('global-search.index', ['q' => 'Alfa']))
            ->assertOk()
            ->assertSee($localParty->name)
            ->assertDontSee('SECRETO');

        $this->actingAs($actor)
            ->get(route('global-search.index', ['q' => $imei]))
            ->assertOk()
            ->assertSee('Orden #7101')
            ->assertSee(
                route('service-orders.show', $localOrder),
                false
            )
            ->assertDontSee('Orden #9901')
            ->assertDontSee(
                route('service-orders.show', $foreignOrder),
                false
            );
    }

    public function test_purchase_and_sale_results_use_real_domain_evidence_and_stay_scoped(): void
    {
        $local = $this->organization();
        $foreign = $this->newOrganization('Search comercio externo');
        $actor = $this->user($local, UserRole::Operator);
        $foreignActor = $this->user($foreign, UserRole::Operator);

        $localCustomer = $this->party(
            $local,
            'Cliente Venta Alfa Local'
        );
        $foreignCustomer = $this->party(
            $foreign,
            'Cliente Venta Alfa SECRETO'
        );
        $localSupplier = $this->supplier(
            $local,
            'Proveedor Alfa Local'
        );
        $foreignSupplier = $this->supplier(
            $foreign,
            'Proveedor Alfa SECRETO'
        );

        $localPurchase = $this->purchase(
            $local,
            $actor,
            $localSupplier,
            'alfa-local'
        );
        $foreignPurchase = $this->purchase(
            $foreign,
            $foreignActor,
            $foreignSupplier,
            'alfa-foreign'
        );
        $localSale = $this->sale(
            $local,
            $actor,
            $localCustomer,
            1250000,
            8201
        );
        $foreignSale = $this->sale(
            $foreign,
            $foreignActor,
            $foreignCustomer,
            9900000,
            9201
        );

        $response = $this->actingAs($actor)
            ->get(route('global-search.index', ['q' => 'Alfa']));

        $response
            ->assertOk()
            ->assertSee('Compras')
            ->assertSee('Ventas')
            ->assertSee(route(
                'purchase-orders.show',
                $localPurchase
            ), false)
            ->assertSee(route(
                'commerce-sales.show',
                $localSale
            ), false)
            ->assertDontSee(route(
                'purchase-orders.show',
                $foreignPurchase
            ), false)
            ->assertDontSee(route(
                'commerce-sales.show',
                $foreignSale
            ), false)
            ->assertDontSee('SECRETO');
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
                'slug' => Str::slug($name)
                    .'-'
                    .Str::lower(Str::random(6)),
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

    private function category(): ProductCategory
    {
        return ProductCategory::withoutEvents(
            fn () => ProductCategory::query()->firstOrCreate(
                ['slug' => 'global-search-tests'],
                [
                    'name' => 'Global Search Tests',
                    'active' => true,
                ]
            )
        );
    }

    private function product(
        string $name,
        string $sku
    ): CatalogProduct {
        return CatalogProduct::query()->create([
            'product_category_id' => $this->category()->id,
            'sku' => $sku,
            'name' => $name,
            'base_unit_code' => 'unit',
            'quantity_scale' => 0,
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
            'email' => Str::lower(Str::random(10))
                .'@global-search.test',
        ]);
    }

    private function supplier(
        Organization $organization,
        string $name
    ): Supplier {
        $party = BusinessParty::query()->create([
            'organization_id' => $organization->id,
            'party_type' => BusinessParty::TYPE_ORGANIZATION,
            'name' => $name,
            'email' => Str::lower(Str::random(10))
                .'@search-supplier.test',
        ]);

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
                'brand_name' => 'Search',
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
                'idempotency_key' => 'search:service:'
                    .$organization->id
                    .':'
                    .$number,
                'metadata' => [],
            ])
        )->load('asset');
    }

    private function identifier(
        ServiceOrder $order,
        User $actor,
        string $value
    ): ServiceAssetIdentifier {
        return ServiceAssetIdentifier::query()->create([
            'organization_id' => $order->organization_id,
            'service_asset_id' => $order->service_asset_id,
            'identifier_type' => ServiceIdentifierType::Imei,
            'value' => $value,
            'created_by_user_id' => $actor->id,
        ]);
    }

    private function purchase(
        Organization $organization,
        User $actor,
        Supplier $supplier,
        string $suffix
    ): PurchaseOrder {
        $product = $this->product(
            'Producto compra '.$suffix.' '.$organization->id,
            'SEARCH-PURCHASE-'
                .$organization->id
                .'-'
                .Str::upper($suffix)
        );
        $manager = app(PurchaseOrderManager::class);
        $order = $manager->draft(
            new PurchaseOrderDraftData(
                supplierId: $supplier->id,
                currencyCode: 'ARS',
                idempotencyKey: 'search:purchase:'
                    .$organization->id
                    .':'
                    .$suffix,
                lines: [new PurchaseOrderLineData(
                    catalogProductId: $product->id,
                    quantity: '1',
                    unitCostMinor: 100000,
                    description: 'Fixture búsqueda global'
                )],
                notes: 'Búsqueda global '.$suffix
            ),
            $actor
        );

        return $manager->issue($order, $actor);
    }

    private function sale(
        Organization $organization,
        User $actor,
        BusinessParty $customer,
        int $totalMinor,
        int $number
    ): CommerceSale {
        $product = $this->product(
            'Producto venta search '.$number,
            'SEARCH-SALE-'.$organization->id.'-'.$number
        );
        $location = InventoryLocation::query()
            ->where('organization_id', $organization->id)
            ->orderBy('id')
            ->first()
            ?? $this->newLocation(
                $organization,
                'Depósito ventas search '.$organization->id
            );

        $movement = app(InventoryMovementCreator::class)->create(
            new InventoryMovementDraftData(
                type: InventoryMovementType::Receipt,
                effectiveAt: now(),
                reason: 'Stock fixture búsqueda global.',
                idempotencyKey: 'search:stock:'
                    .$organization->id
                    .':'
                    .$number,
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
                currencyCode: 'ARS',
                idempotencyKey: 'search:sale:'
                    .$organization->id
                    .':'
                    .$number,
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
                notes: 'Búsqueda global',
                soldAt: now()
            ),
            $actor
        );
    }
}
