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
use App\Enums\InventoryMovementStatus;
use App\Enums\InventoryMovementType;
use App\Enums\UserRole;
use App\Http\Middleware\RequireOrganization;
use App\Models\BusinessParty;
use App\Models\CatalogProduct;
use App\Models\CommercePostSaleReceipt;
use App\Models\CommercePostSaleRequest;
use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductCategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CommercePostSaleReceiptHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_receipt_routes_and_permissions_are_explicit(): void
    {
        $fixture =
            $this->fixture('routes');

        foreach ([
            'commerce-post-sale.receipts.create' => 'GET',
            'commerce-post-sale.receipts.store' => 'POST',
        ] as $name => $method) {
            $route =
                app('router')
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
                'can:record-commerce-post-sale',
                $route->gatherMiddleware()
            );
        }

        $this->actingAs(
            $fixture['operator']
        )
            ->get(
                route(
                    'commerce-post-sale.receipts.create',
                    $fixture['request']
                )
            )
            ->assertOk()
            ->assertSee(
                'Confirmar mercadería recibida'
            );

        $this->actingAs(
            $fixture['viewer']
        )
            ->get(
                route(
                    'commerce-post-sale.receipts.create',
                    $fixture['request']
                )
            )
            ->assertForbidden();

        $this->actingAs(
            $fixture['viewer']
        )
            ->post(
                route(
                    'commerce-post-sale.receipts.store',
                    $fixture['request']
                ),
                []
            )
            ->assertForbidden();
    }

    public function test_operator_confirms_partial_receipt_and_exact_retry_is_idempotent(): void
    {
        $fixture =
            $this->fixture('partial');

        $requestLine =
            $fixture['request']
                ->lines
                ->sole();

        $inventoryBefore =
            DB::table(
                'inventory_movements'
            )->count();

        $payload = [
            'idempotency_key' =>
                'p852:http:partial',
            'notes' =>
                'Primera unidad efectivamente recibida.',
            'lines' => [[
                'selected' => '1',
                'commerce_post_sale_request_line_id' =>
                    $requestLine->id,
                'quantity' => '1',
                'condition' =>
                    InventoryCondition::Used
                        ->value,
                'destination_location_id' =>
                    $fixture['location']->id,
                'notes' =>
                    'Unidad con marcas de uso.',
            ]],
        ];

        $response =
            $this->actingAs(
                $fixture['operator']
            )->post(
                route(
                    'commerce-post-sale.receipts.store',
                    $fixture['request']
                ),
                $payload
            );

        $receipt =
            CommercePostSaleReceipt::query()
                ->with([
                    'inventoryMovement',
                    'lines',
                ])
                ->sole();

        $response
            ->assertRedirect(
                route(
                    'commerce-post-sale.show',
                    $fixture['request']
                )
            )
            ->assertSessionHasNoErrors();

        $this->assertSame(
            InventoryMovementType::CustomerReturn,
            $receipt
                ->inventoryMovement
                ->type
        );

        $this->assertSame(
            InventoryMovementStatus::Confirmed,
            $receipt
                ->inventoryMovement
                ->status
        );

        $this->assertSame(
            $inventoryBefore + 1,
            DB::table(
                'inventory_movements'
            )->count()
        );

        $receiptLine =
            $receipt->lines->sole();

        $this->assertSame(
            '1.000000',
            $receiptLine->quantity
        );

        $this->assertSame(
            InventoryCondition::Used,
            $receiptLine->condition
        );

        $this->assertSame(
            $fixture['location']->id,
            $receiptLine
                ->destination_location_id
        );

        $balance =
            InventoryBalance::query()
                ->forOrganization(
                    $fixture[
                        'organization'
                    ]->id
                )
                ->where(
                    'catalog_product_id',
                    $fixture['product']->id
                )
                ->where(
                    'inventory_location_id',
                    $fixture['location']->id
                )
                ->where(
                    'condition',
                    InventoryCondition::Used
                        ->value
                )
                ->firstOrFail();

        $this->assertSame(
            '1.000000',
            $balance->quantity
        );

        $this->actingAs(
            $fixture['operator']
        )
            ->post(
                route(
                    'commerce-post-sale.receipts.store',
                    $fixture['request']
                ),
                $payload
            )
            ->assertRedirect(
                route(
                    'commerce-post-sale.show',
                    $fixture['request']
                )
            )
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount(
            'commerce_post_sale_receipts',
            1
        );

        $this->assertSame(
            $inventoryBefore + 1,
            DB::table(
                'inventory_movements'
            )->count()
        );

        $this->actingAs(
            $fixture['operator']
        )
            ->get(
                route(
                    'commerce-post-sale.receipts.create',
                    $fixture['request']
                )
            )
            ->assertOk()
            ->assertSee(
                'solicitado 2'
            )
            ->assertSee(
                'recibido 1'
            )
            ->assertSee(
                'Pendiente'
            );

        $this->actingAs(
            $fixture['operator']
        )
            ->get(
                route(
                    'commerce-post-sale.show',
                    $fixture['request']
                )
            )
            ->assertOk()
            ->assertSee(
                'Registrar recepción física'
            )
            ->assertSee(
                'CustomerReturn #'
                .$receipt
                    ->inventoryMovement
                    ->id
            )
            ->assertSee(
                'Usado'
            )
            ->assertSee(
                $fixture['location']->name
            );
    }

    public function test_cumulative_overreceipt_fails_closed_without_second_inventory_effect(): void
    {
        $fixture =
            $this->fixture('over');

        $line =
            $fixture['request']
                ->lines
                ->sole();

        $this->postReceipt(
            $fixture,
            $line->id,
            '1',
            'p852:over:first'
        )
            ->assertSessionHasNoErrors();

        $inventoryAfterFirst =
            DB::table(
                'inventory_movements'
            )->count();

        $this->actingAs(
            $fixture['operator']
        )
            ->from(
                route(
                    'commerce-post-sale.receipts.create',
                    $fixture['request']
                )
            )
            ->post(
                route(
                    'commerce-post-sale.receipts.store',
                    $fixture['request']
                ),
                [
                    'idempotency_key' =>
                        'p852:over:second',
                    'lines' => [[
                        'selected' => '1',
                        'commerce_post_sale_request_line_id' =>
                            $line->id,
                        'quantity' => '2',
                        'condition' =>
                            InventoryCondition::Used
                                ->value,
                        'destination_location_id' =>
                            $fixture[
                                'location'
                            ]->id,
                    ]],
                ]
            )
            ->assertRedirect(
                route(
                    'commerce-post-sale.receipts.create',
                    $fixture['request']
                )
            )
            ->assertSessionHasErrors(
                'post_sale_receipt'
            );

        $this->assertDatabaseCount(
            'commerce_post_sale_receipts',
            1
        );

        $this->assertDatabaseCount(
            'commerce_post_sale_receipt_lines',
            1
        );

        $this->assertSame(
            $inventoryAfterFirst,
            DB::table(
                'inventory_movements'
            )->count()
        );
    }

    public function test_complete_receipt_closes_physical_intake_without_money_effect(): void
    {
        $fixture =
            $this->fixture('complete');

        $line =
            $fixture['request']
                ->lines
                ->sole();

        $paymentsBefore =
            DB::table(
                'commerce_payments'
            )->count();

        $cashBefore =
            DB::table(
                'cash_movements'
            )->count();

        $this->postReceipt(
            $fixture,
            $line->id,
            '2',
            'p852:complete'
        )
            ->assertSessionHasNoErrors();

        $this->actingAs(
            $fixture['operator']
        )
            ->get(
                route(
                    'commerce-post-sale.receipts.create',
                    $fixture['request']
                )
            )
            ->assertOk()
            ->assertSee(
                'Recepción física completa'
            )
            ->assertDontSee(
                'Confirmar recepción física',
                escape: false
            );

        $this->assertSame(
            $paymentsBefore,
            DB::table(
                'commerce_payments'
            )->count()
        );

        $this->assertSame(
            $cashBefore,
            DB::table(
                'cash_movements'
            )->count()
        );

        $this->assertDatabaseCount(
            'commerce_post_sale_resolutions',
            0
        );
    }

    public function test_foreign_case_and_location_are_hidden_or_rejected(): void
    {
        $fixture =
            $this->fixture('tenant');

        $other =
            Organization::query()
                ->create([
                    'name' =>
                        'Otra organización P8.5.2',
                    'slug' =>
                        'otra-organizacion-p852',
                    'active' => true,
                ]);

        $foreignOperator =
            $this->user(
                $other,
                UserRole::Operator
            );

        $foreignLocation =
            InventoryLocation::query()
                ->create([
                    'organization_id' =>
                        $other->id,
                    'name' =>
                        'Depósito extranjero P8.5.2',
                    'type' =>
                        \App\Enums\InventoryLocationType::Warehouse,
                    'active' => true,
                ]);

        $this->actingAs(
            $foreignOperator
        )
            ->get(
                route(
                    'commerce-post-sale.receipts.create',
                    $fixture['request']
                )
            )
            ->assertNotFound();

        $line =
            $fixture['request']
                ->lines
                ->sole();

        $this->actingAs(
            $fixture['operator']
        )
            ->from(
                route(
                    'commerce-post-sale.receipts.create',
                    $fixture['request']
                )
            )
            ->post(
                route(
                    'commerce-post-sale.receipts.store',
                    $fixture['request']
                ),
                [
                    'idempotency_key' =>
                        'p852:foreign-location',
                    'lines' => [[
                        'selected' => '1',
                        'commerce_post_sale_request_line_id' =>
                            $line->id,
                        'quantity' => '1',
                        'condition' =>
                            InventoryCondition::Used
                                ->value,
                        'destination_location_id' =>
                            $foreignLocation->id,
                    ]],
                ]
            )
            ->assertRedirect(
                route(
                    'commerce-post-sale.receipts.create',
                    $fixture['request']
                )
            )
            ->assertSessionHasErrors(
                'lines.0.destination_location_id'
            );

        $this->assertDatabaseCount(
            'commerce_post_sale_receipts',
            0
        );
    }

    private function postReceipt(
        array $fixture,
        int $requestLineId,
        string $quantity,
        string $idempotencyKey
    ) {
        return $this->actingAs(
            $fixture['operator']
        )->post(
            route(
                'commerce-post-sale.receipts.store',
                $fixture['request']
            ),
            [
                'idempotency_key' =>
                    $idempotencyKey,
                'lines' => [[
                    'selected' => '1',
                    'commerce_post_sale_request_line_id' =>
                        $requestLineId,
                    'quantity' =>
                        $quantity,
                    'condition' =>
                        InventoryCondition::Used
                            ->value,
                    'destination_location_id' =>
                        $fixture['location']->id,
                ]],
            ]
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
                ->where('active', true)
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
                        'Cliente recepción P8.5.2 '
                        .$suffix,
                ]);

        $category =
            ProductCategory::withoutEvents(
                fn () =>
                    ProductCategory::query()
                        ->firstOrCreate(
                            [
                                'slug' =>
                                    'post-sale-receipt-http-tests',
                            ],
                            [
                                'name' =>
                                    'Posventa Recepción HTTP',
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
                                'P852-'
                                .Str::upper(
                                    Str::random(8)
                                ),
                            'name' =>
                                'Producto recepción P8.5.2 '
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
            'Precio HTTP P8.5.2.',
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
                        'Stock para recepción HTTP P8.5.2.',
                    idempotencyKey:
                        'p852:stock:'
                        .$suffix.':'
                        .$admin->id,
                    lines: [
                        new InventoryMovementLineData(
                            catalogProductId:
                                $product->id,
                            condition:
                                InventoryCondition::New,
                            enteredQuantity: '2',
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
                'Banco recepción P8.5.2 '
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
                        'p852:sale:'
                        .$suffix.':'
                        .$admin->id,
                    payments: [
                        new CommercePaymentData(
                            method:
                                CommercePaymentMethod::BankTransfer,
                            amountMinor:
                                20000,
                            reference:
                                'P852-'
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
                            quantity: '2',
                            unitPriceMinor:
                                10000
                        ),
                    ],
                    customerBusinessPartyId:
                        $party->id
                ),
                $admin
            )->load('lines');

        $saleLine =
            $sale->lines->sole();

        $postSale =
            app(
                CommercePostSaleRequestManager::class
            )->create(
                new CommercePostSaleRequestData(
                    commerceSaleId:
                        $sale->id,
                    intent:
                        CommercePostSaleIntent::Return,
                    lines: [
                        new CommercePostSaleRequestLineData(
                            commerceSaleLineId:
                                $saleLine->id,
                            quantity: '2'
                        ),
                    ],
                    reason:
                        'El cliente solicita devolución física de las dos unidades.',
                    idempotencyKey:
                        'p852:request:'
                        .$suffix.':'
                        .$operator->id
                ),
                $operator
            )->load('lines');

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
            'location' => $location,
            'product' => $product,
            'sale' => $sale,
            'request' => $postSale,
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
