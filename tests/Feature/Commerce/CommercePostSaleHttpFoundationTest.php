<?php

namespace Tests\Feature\Commerce;

use App\Domain\Commerce\CommerceCheckoutData;
use App\Domain\Commerce\CommerceCheckoutManager;
use App\Domain\Commerce\CommercePaymentData;
use App\Domain\Commerce\CommercePostSaleRequestData;
use App\Domain\Commerce\CommercePostSaleRequestLineData;
use App\Domain\Commerce\CommercePostSaleRequestManager;
use App\Domain\Commerce\CommerceProductLineData;
use App\Domain\Commerce\OrganizationProductPriceManager;
use App\Domain\Finance\FinancialAccountManager;
use App\Domain\Inventory\InventoryMovementConfirmer;
use App\Domain\Inventory\InventoryMovementCreator;
use App\Domain\Inventory\InventoryMovementDraftData;
use App\Domain\Inventory\InventoryMovementLineData;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CommercePaymentMethod;
use App\Enums\CommercePostSaleIntent;
use App\Enums\FinancialAccountType;
use App\Enums\InventoryCondition;
use App\Enums\InventoryMovementType;
use App\Enums\UserRole;
use App\Http\Middleware\RequireOrganization;
use App\Models\BusinessParty;
use App\Models\CatalogProduct;
use App\Models\CommercePostSaleRequest;
use App\Models\CommerceSale;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductCategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CommercePostSaleHttpFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_routes_gates_and_viewer_read_surface_are_explicit(): void
    {
        $fixture = $this->fixture('routes');

        $postSale = app(
            CommercePostSaleRequestManager::class
        )->create(
            new CommercePostSaleRequestData(
                commerceSaleId:
                    $fixture['sale']->id,
                intent:
                    CommercePostSaleIntent::Return,
                lines: [
                    new CommercePostSaleRequestLineData(
                        commerceSaleLineId:
                            $fixture['sale']
                                ->lines
                                ->sole()
                                ->id,
                        quantity: '1'
                    ),
                ],
                reason:
                    'El cliente solicita devolver el artículo vendido.',
                idempotencyKey:
                    'p851:routes:'
                    .$fixture['operator']->id
            ),
            $fixture['operator']
        );

        $routes = [
            'commerce-post-sale.index' => [
                'GET',
                'can:view-commerce-post-sale',
            ],
            'commerce-post-sale.show' => [
                'GET',
                'can:view-commerce-post-sale',
            ],
            'commerce-post-sale.create' => [
                'GET',
                'can:record-commerce-post-sale',
            ],
            'commerce-post-sale.store' => [
                'POST',
                'can:record-commerce-post-sale',
            ],
        ];

        foreach (
            $routes as $name => [$method, $ability]
        ) {
            $route = app('router')
                ->getRoutes()
                ->getByName($name);

            $this->assertNotNull(
                $route,
                $name
            );

            $this->assertContains(
                $method,
                $route->methods()
            );

            $this->assertContains(
                RequireOrganization::class,
                $route->gatherMiddleware()
            );

            $this->assertContains(
                $ability,
                $route->gatherMiddleware()
            );
        }

        $viewer = $fixture['viewer'];

        $this->actingAs($viewer)
            ->get(
                route(
                    'commerce-post-sale.index'
                )
            )
            ->assertOk()
            ->assertSee(
                'Expedientes de posventa'
            )
            ->assertSee(
                'Venta #'
                .$fixture['sale']
                    ->sale_number
            );

        $this->actingAs($viewer)
            ->get(
                route(
                    'commerce-post-sale.show',
                    $postSale
                )
            )
            ->assertOk()
            ->assertSee(
                'Expediente de posventa'
            )
            ->assertSee(
                'Recepciones confirmadas'
            )
            ->assertSee(
                'Resoluciones económicas'
            );

        $this->actingAs($viewer)
            ->get(
                route(
                    'commerce-post-sale.create',
                    $fixture['sale']
                )
            )
            ->assertForbidden();

        $this->actingAs($viewer)
            ->post(
                route(
                    'commerce-post-sale.store',
                    $fixture['sale']
                ),
                []
            )
            ->assertForbidden();
    }

    public function test_operator_registers_post_sale_request_from_sale_http(): void
    {
        $fixture =
            $this->fixture('store');

        $saleLine =
            $fixture['sale']
                ->lines
                ->sole();

        $this->actingAs(
            $fixture['operator']
        )
            ->get(
                route(
                    'commerce-post-sale.create',
                    $fixture['sale']
                )
            )
            ->assertOk()
            ->assertSee(
                'Nueva solicitud de posventa'
            )
            ->assertSee(
                $fixture['product']->name
            );

        $response =
            $this->actingAs(
                $fixture['operator']
            )->post(
                route(
                    'commerce-post-sale.store',
                    $fixture['sale']
                ),
                [
                    'intent' =>
                        CommercePostSaleIntent::Exchange
                            ->value,
                    'reason' =>
                        'El cliente solicita cambiar el producto por otra unidad.',
                    'notes' =>
                        'Recepción física pendiente.',
                    'idempotency_key' =>
                        'p851:http:store',
                    'lines' => [[
                        'selected' => '1',
                        'commerce_sale_line_id' =>
                            $saleLine->id,
                        'quantity' => '1',
                    ]],
                ]
            );

        $postSale =
            CommercePostSaleRequest::query()
                ->with('lines')
                ->sole();

        $response
            ->assertRedirect(
                route(
                    'commerce-post-sale.show',
                    $postSale
                )
            )
            ->assertSessionHasNoErrors();

        $this->assertSame(
            $fixture['organization']->id,
            $postSale->organization_id
        );

        $this->assertSame(
            $fixture['sale']->id,
            $postSale->commerce_sale_id
        );

        $this->assertSame(
            CommercePostSaleIntent::Exchange,
            $postSale->intent
        );

        $this->assertSame(
            $fixture['operator']->id,
            $postSale->requested_by_user_id
        );

        $this->assertSame(
            '1.000000',
            $postSale->lines
                ->sole()
                ->quantity
        );

        $this->actingAs(
            $fixture['operator']
        )
            ->get(
                route(
                    'commerce-post-sale.show',
                    $postSale
                )
            )
            ->assertOk()
            ->assertSee(
                'Cambio'
            )
            ->assertSee(
                'Recepciones físicas'
            )
            ->assertSee(
                'Resoluciones económicas'
            );

        $this->actingAs(
            $fixture['operator']
        )
            ->get(
                route(
                    'commerce-sales.show',
                    $fixture['sale']
                )
            )
            ->assertOk()
            ->assertSee(
                'Iniciar posventa'
            )
            ->assertSee(
                'Posventa'
            )
            ->assertSee(
                'Cambio'
            );
    }

    public function test_http_surface_hides_foreign_tenant_sale_and_case(): void
    {
        $fixture =
            $this->fixture('tenant');

        $postSale = app(
            CommercePostSaleRequestManager::class
        )->create(
            new CommercePostSaleRequestData(
                commerceSaleId:
                    $fixture['sale']->id,
                intent:
                    CommercePostSaleIntent::Return,
                lines: [
                    new CommercePostSaleRequestLineData(
                        commerceSaleLineId:
                            $fixture['sale']
                                ->lines
                                ->sole()
                                ->id,
                        quantity: '1'
                    ),
                ],
                reason:
                    'El cliente solicita una devolución por disconformidad.',
                idempotencyKey:
                    'p851:tenant:'
                    .$fixture['operator']->id
            ),
            $fixture['operator']
        );

        $other =
            Organization::query()
                ->create([
                    'name' =>
                        'Otra organización P8.5.1',
                    'slug' =>
                        'otra-organizacion-p851',
                    'active' => true,
                ]);

        $foreignOperator =
            $this->user(
                $other,
                UserRole::Operator
            );

        $this->actingAs(
            $foreignOperator
        )
            ->get(
                route(
                    'commerce-post-sale.create',
                    $fixture['sale']
                )
            )
            ->assertNotFound();

        $this->actingAs(
            $foreignOperator
        )
            ->get(
                route(
                    'commerce-post-sale.show',
                    $postSale
                )
            )
            ->assertNotFound();

        $this->actingAs(
            $foreignOperator
        )
            ->post(
                route(
                    'commerce-post-sale.store',
                    $fixture['sale']
                ),
                [
                    'intent' => 'return',
                    'reason' =>
                        'Intento cruzado que no debe revelar la venta.',
                    'idempotency_key' =>
                        'p851:foreign',
                    'lines' => [],
                ]
            )
            ->assertNotFound();

        $this->actingAs(
            $foreignOperator
        )
            ->get(
                route(
                    'commerce-post-sale.index'
                )
            )
            ->assertOk()
            ->assertDontSee(
                'Venta #'
                .$fixture['sale']
                    ->sale_number
            );
    }

    public function test_http_intake_reuses_domain_quantity_guard_and_rolls_back(): void
    {
        $fixture =
            $this->fixture('quantity');

        $saleLine =
            $fixture['sale']
                ->lines
                ->sole();

        $this->actingAs(
            $fixture['operator']
        )
            ->from(
                route(
                    'commerce-post-sale.create',
                    $fixture['sale']
                )
            )
            ->post(
                route(
                    'commerce-post-sale.store',
                    $fixture['sale']
                ),
                [
                    'intent' => 'return',
                    'reason' =>
                        'El cliente solicita devolver más unidades que las vendidas.',
                    'idempotency_key' =>
                        'p851:quantity',
                    'lines' => [[
                        'selected' => '1',
                        'commerce_sale_line_id' =>
                            $saleLine->id,
                        'quantity' => '2',
                    ]],
                ]
            )
            ->assertRedirect(
                route(
                    'commerce-post-sale.create',
                    $fixture['sale']
                )
            )
            ->assertSessionHasErrors(
                'post_sale'
            );

        $this->assertDatabaseCount(
            'commerce_post_sale_requests',
            0
        );

        $this->assertDatabaseCount(
            'commerce_post_sale_request_lines',
            0
        );
    }

    private function fixture(
        string $suffix
    ): array {
        $organization =
            Organization::query()
                ->where(
                    'slug',
                    'sulu-tv'
                )
                ->firstOrFail();

        $admin =
            $this->user(
                $organization,
                UserRole::Admin
            );

        $operator =
            $this->user(
                $organization,
                UserRole::Operator
            );

        $viewer =
            $this->user(
                $organization,
                UserRole::Viewer
            );

        $location =
            InventoryLocation::query()
                ->forOrganization(
                    $organization->id
                )
                ->active()
                ->orderBy('id')
                ->firstOrFail();

        $party =
            BusinessParty::query()
                ->create([
                    'organization_id' =>
                        $organization->id,
                    'party_type' =>
                        BusinessParty::TYPE_PERSON,
                    'name' =>
                        'Cliente HTTP P8.5.1 '
                        .$suffix,
                ]);

        $category =
            ProductCategory::withoutEvents(
                fn () =>
                    ProductCategory::query()
                        ->firstOrCreate(
                            [
                                'slug' =>
                                    'post-sale-http-tests',
                            ],
                            [
                                'name' =>
                                    'Posventa HTTP',
                                'active' => true,
                            ]
                        )
            );

        $product =
            CatalogProduct::withoutEvents(
                fn () =>
                    CatalogProduct::query()
                        ->create([
                            'product_category_id' =>
                                $category->id,
                            'sku' =>
                                'P851-'
                                .Str::upper(
                                    Str::random(8)
                                ),
                            'name' =>
                                'Producto HTTP P8.5.1 '
                                .$suffix,
                            'active' => true,
                        ])
                        ->refresh()
            );

        app(
            OrganizationProductPriceManager::class
        )->set(
            $product,
            'ARS',
            10000,
            'Precio HTTP P8.5.1.',
            $admin
        );

        $stock =
            app(
                InventoryMovementCreator::class
            )->create(
                new InventoryMovementDraftData(
                    type:
                        InventoryMovementType::Receipt,
                    effectiveAt:
                        CarbonImmutable::now(),
                    reason:
                        'Stock para HTTP P8.5.1.',
                    idempotencyKey:
                        'p851:stock:'
                        .$suffix.':'
                        .$admin->id,
                    lines: [
                        new InventoryMovementLineData(
                            catalogProductId:
                                $product->id,
                            condition:
                                InventoryCondition::New,
                            enteredQuantity: '1',
                            enteredUnitCode:
                                $product
                                    ->base_unit_code,
                            destinationLocationId:
                                $location->id
                        ),
                    ]
                ),
                $admin
            );

        app(
            InventoryMovementConfirmer::class
        )->confirm(
            $stock,
            $admin
        );

        $bank =
            app(
                FinancialAccountManager::class
            )->create(
                'Banco HTTP P8.5.1 '
                .$suffix,
                FinancialAccountType::BankAccount,
                'ARS',
                $admin
            );

        $sale =
            app(
                CommerceCheckoutManager::class
            )->checkout(
                new CommerceCheckoutData(
                    currencyCode: 'ARS',
                    idempotencyKey:
                        'p851:sale:'
                        .$suffix.':'
                        .$admin->id,
                    payments: [
                        new CommercePaymentData(
                            method:
                                CommercePaymentMethod::BankTransfer,
                            amountMinor:
                                10000,
                            reference:
                                'P851-'
                                .$suffix,
                            financialAccountId:
                                $bank->id
                        ),
                    ],
                    productLines: [
                        new CommerceProductLineData(
                            catalogProductId:
                                $product->id,
                            sourceLocationId:
                                $location->id,
                            condition:
                                InventoryCondition::New,
                            quantity: '1',
                            unitPriceMinor:
                                10000
                        ),
                    ],
                    customerBusinessPartyId:
                        $party->id
                ),
                $admin
            )
                ->load([
                    'lines.product',
                ]);

        app(CurrentOrganization::class)
            ->forget($operator);

        app(CurrentOrganization::class)
            ->forget($viewer);

        return [
            'organization' =>
                $organization,
            'admin' => $admin,
            'operator' => $operator,
            'viewer' => $viewer,
            'party' => $party,
            'location' => $location,
            'product' => $product,
            'sale' => $sale,
        ];
    }

    private function user(
        Organization $organization,
        UserRole $role
    ): User {
        $user =
            User::factory()->create([
                'role' => $role,
                'current_organization_id' =>
                    $organization->id,
                'email_verified_at' =>
                    now(),
            ]);

        OrganizationMembership::query()
            ->updateOrCreate(
                [
                    'organization_id' =>
                        $organization->id,
                    'user_id' =>
                        $user->id,
                ],
                [
                    'role' => $role,
                    'active' => true,
                ]
            );

        app(CurrentOrganization::class)
            ->forget($user);

        return $user->refresh();
    }
}
