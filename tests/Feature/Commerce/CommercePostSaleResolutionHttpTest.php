<?php

namespace Tests\Feature\Commerce;

use App\Domain\Commerce\CommerceCheckoutData;
use App\Domain\Commerce\CommerceCheckoutManager;
use App\Domain\Commerce\CommercePaymentData;
use App\Domain\Commerce\CommercePostSaleReceiptData;
use App\Domain\Commerce\CommercePostSaleReceiptLineData;
use App\Domain\Commerce\CommercePostSaleReceiptManager;
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
use App\Enums\CommercePostSaleResolutionOutcome;
use App\Enums\FinancialAccountType;
use App\Enums\InventoryCondition;
use App\Enums\InventoryMovementType;
use App\Enums\UserRole;
use App\Http\Middleware\RequireOrganization;
use App\Models\BusinessParty;
use App\Models\CatalogProduct;
use App\Models\CommercePostSaleResolution;
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

class CommercePostSaleResolutionHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_resolution_routes_are_admin_only_and_tenant_scoped(): void
    {
        $fixture =
            $this->fixture('routes');

        foreach ([
            'commerce-post-sale.resolutions.create' => 'GET',
            'commerce-post-sale.resolutions.store' => 'POST',
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
                'can:resolve-commerce-post-sale',
                $route->gatherMiddleware()
            );
        }

        $this->actingAs(
            $fixture['admin']
        )
            ->get(
                route(
                    'commerce-post-sale.resolutions.create',
                    $fixture['request']
                )
            )
            ->assertOk()
            ->assertSee(
                'Resolver valor reconocido'
            );

        $this->actingAs(
            $fixture['operator']
        )
            ->get(
                route(
                    'commerce-post-sale.resolutions.create',
                    $fixture['request']
                )
            )
            ->assertForbidden();

        $this->actingAs(
            $fixture['viewer']
        )
            ->post(
                route(
                    'commerce-post-sale.resolutions.store',
                    $fixture['request']
                ),
                []
            )
            ->assertForbidden();
    }

    public function test_admin_records_exact_exchange_resolution_without_execution_and_retry_is_idempotent(): void
    {
        $fixture =
            $this->fixture('exchange');

        $receiptLine =
            $fixture['receipt']
                ->lines
                ->sole();

        $cashBefore =
            DB::table(
                'cash_movements'
            )->count();

        $inventoryBefore =
            DB::table(
                'inventory_movements'
            )->count();

        $paymentsBefore =
            DB::table(
                'commerce_payments'
            )->count();

        $payload = [
            'outcome' =>
                CommercePostSaleResolutionOutcome::Exchange
                    ->value,
            'reason' =>
                'Se reconoce una unidad recibida al valor original para continuar un cambio.',
            'idempotency_key' =>
                'p853:http:exchange',
            'lines' => [[
                'selected' => '1',
                'commerce_post_sale_receipt_line_id' =>
                    $receiptLine->id,
                'quantity' => '1',
                'recognized_amount' =>
                    '100,00',
            ]],
        ];

        $response =
            $this->actingAs(
                $fixture['admin']
            )->post(
                route(
                    'commerce-post-sale.resolutions.store',
                    $fixture['request']
                ),
                $payload
            );

        $resolution =
            CommercePostSaleResolution::query()
                ->with('lines')
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
            CommercePostSaleResolutionOutcome::Exchange,
            $resolution->outcome
        );

        $this->assertSame(
            10000,
            $resolution
                ->recognizedAmountMinor()
        );

        $line =
            $resolution->lines->sole();

        $this->assertSame(
            10000,
            $line->baseline_amount_minor
        );

        $this->assertSame(
            10000,
            $line->recognized_amount_minor
        );

        $this->assertSame(
            $cashBefore,
            DB::table(
                'cash_movements'
            )->count()
        );

        $this->assertSame(
            $inventoryBefore,
            DB::table(
                'inventory_movements'
            )->count()
        );

        $this->assertSame(
            $paymentsBefore,
            DB::table(
                'commerce_payments'
            )->count()
        );

        $this->assertDatabaseCount(
            'customer_credit_grants',
            0
        );

        $this->assertDatabaseCount(
            'commerce_post_sale_exchange_selections',
            0
        );

        $this->assertDatabaseCount(
            'commerce_post_sale_cash_refund_executions',
            0
        );

        $this->assertDatabaseCount(
            'commerce_post_sale_external_refund_instructions',
            0
        );

        $this->actingAs(
            $fixture['admin']
        )
            ->post(
                route(
                    'commerce-post-sale.resolutions.store',
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
            'commerce_post_sale_resolutions',
            1
        );

        $this->actingAs(
            $fixture['admin']
        )
            ->get(
                route(
                    'commerce-post-sale.show',
                    $fixture['request']
                )
            )
            ->assertOk()
            ->assertSee(
                'Registrar resolución económica'
            )
            ->assertSee(
                'Reconocido $ 100,00'
            )
            ->assertSee(
                'Selección de reemplazo pendiente'
            );
    }

    public function test_reduced_value_requires_adjustment_reason_through_domain(): void
    {
        $fixture =
            $this->fixture('adjustment');

        $receiptLine =
            $fixture['receipt']
                ->lines
                ->sole();

        $url =
            route(
                'commerce-post-sale.resolutions.store',
                $fixture['request']
            );

        $create =
            route(
                'commerce-post-sale.resolutions.create',
                $fixture['request']
            );

        $base = [
            'outcome' =>
                CommercePostSaleResolutionOutcome::CustomerCredit
                    ->value,
            'reason' =>
                'Se reconoce un valor reducido por la condición física constatada.',
            'lines' => [[
                'selected' => '1',
                'commerce_post_sale_receipt_line_id' =>
                    $receiptLine->id,
                'quantity' => '1',
                'recognized_amount' =>
                    '70.00',
            ]],
        ];

        $this->actingAs(
            $fixture['admin']
        )
            ->from($create)
            ->post(
                $url,
                [
                    ...$base,
                    'idempotency_key' =>
                        'p853:adjustment:missing',
                ]
            )
            ->assertRedirect($create)
            ->assertSessionHasErrors(
                'post_sale_resolution'
            );

        $this->assertDatabaseCount(
            'commerce_post_sale_resolutions',
            0
        );

        $payload = $base;
        $payload['idempotency_key'] =
            'p853:adjustment:ok';
        $payload['lines'][0][
            'adjustment_reason'
        ] =
            'La unidad volvió usada y presenta desgaste visible comprobado.';

        $this->actingAs(
            $fixture['admin']
        )
            ->post(
                $url,
                $payload
            )
            ->assertRedirect(
                route(
                    'commerce-post-sale.show',
                    $fixture['request']
                )
            )
            ->assertSessionHasNoErrors();

        $resolution =
            CommercePostSaleResolution::query()
                ->with('lines')
                ->sole();

        $line =
            $resolution->lines->sole();

        $this->assertSame(
            10000,
            $line->baseline_amount_minor
        );

        $this->assertSame(
            7000,
            $line->recognized_amount_minor
        );

        $this->assertNotNull(
            $line->adjustment_reason
        );
    }

    public function test_cumulative_over_resolution_fails_closed_and_form_shows_remaining(): void
    {
        $fixture =
            $this->fixture('cumulative');

        $receiptLine =
            $fixture['receipt']
                ->lines
                ->sole();

        $this->resolveHttp(
            $fixture,
            receiptLineId:
                $receiptLine->id,
            quantity: '1',
            recognizedAmount:
                '100.00',
            idempotencyKey:
                'p853:cumulative:first'
        )
            ->assertSessionHasNoErrors();

        $create =
            route(
                'commerce-post-sale.resolutions.create',
                $fixture['request']
            );

        $this->actingAs(
            $fixture['admin']
        )
            ->get($create)
            ->assertOk()
            ->assertSee(
                'recibido 2'
            )
            ->assertSee(
                'resuelto 1'
            )
            ->assertSee(
                'Pendiente'
            );

        $this->actingAs(
            $fixture['admin']
        )
            ->from($create)
            ->post(
                route(
                    'commerce-post-sale.resolutions.store',
                    $fixture['request']
                ),
                [
                    'outcome' =>
                        CommercePostSaleResolutionOutcome::Exchange
                            ->value,
                    'reason' =>
                        'Se intenta resolver más cantidad que la físicamente pendiente.',
                    'idempotency_key' =>
                        'p853:cumulative:second',
                    'lines' => [[
                        'selected' => '1',
                        'commerce_post_sale_receipt_line_id' =>
                            $receiptLine->id,
                        'quantity' => '2',
                        'recognized_amount' =>
                            '200.00',
                    ]],
                ]
            )
            ->assertRedirect($create)
            ->assertSessionHasErrors(
                'post_sale_resolution'
            );

        $this->assertDatabaseCount(
            'commerce_post_sale_resolutions',
            1
        );
    }

    public function test_refund_can_reference_only_payment_from_original_sale_without_moving_money(): void
    {
        $fixture =
            $this->fixture('refund');

        $receiptLine =
            $fixture['receipt']
                ->lines
                ->sole();

        $payment =
            $fixture['sale']
                ->payments
                ->sole();

        $externalBefore =
            DB::table(
                'financial_external_movements'
            )->count();

        $this->actingAs(
            $fixture['admin']
        )
            ->post(
                route(
                    'commerce-post-sale.resolutions.store',
                    $fixture['request']
                ),
                [
                    'outcome' =>
                        CommercePostSaleResolutionOutcome::Refund
                            ->value,
                    'reason' =>
                        'Se reconoce el valor original y se prefiere el medio de pago de la venta.',
                    'preferred_original_payment_id' =>
                        $payment->id,
                    'idempotency_key' =>
                        'p853:refund',
                    'lines' => [[
                        'selected' => '1',
                        'commerce_post_sale_receipt_line_id' =>
                            $receiptLine->id,
                        'quantity' => '1',
                        'recognized_amount' =>
                            '100.00',
                    ]],
                ]
            )
            ->assertRedirect(
                route(
                    'commerce-post-sale.show',
                    $fixture['request']
                )
            )
            ->assertSessionHasNoErrors();

        $resolution =
            CommercePostSaleResolution::query()
                ->sole();

        $this->assertSame(
            $payment->id,
            $resolution
                ->preferred_original_payment_id
        );

        $this->assertSame(
            $externalBefore,
            DB::table(
                'financial_external_movements'
            )->count()
        );

        $this->assertDatabaseCount(
            'commerce_post_sale_external_refund_instructions',
            0
        );

        $other =
            $this->fixture('foreign-payment');

        $foreignPayment =
            $other['sale']
                ->payments
                ->sole();

        $foreignReceiptLine =
            $other['receipt']
                ->lines
                ->sole();

        $this->actingAs(
            $fixture['admin']
        )
            ->from(
                route(
                    'commerce-post-sale.resolutions.create',
                    $fixture['request']
                )
            )
            ->post(
                route(
                    'commerce-post-sale.resolutions.store',
                    $fixture['request']
                ),
                [
                    'outcome' =>
                        CommercePostSaleResolutionOutcome::Refund
                            ->value,
                    'reason' =>
                        'Este intento usa un pago que no pertenece a la venta original.',
                    'preferred_original_payment_id' =>
                        $foreignPayment->id,
                    'idempotency_key' =>
                        'p853:refund:foreign-payment',
                    'lines' => [[
                        'selected' => '1',
                        'commerce_post_sale_receipt_line_id' =>
                            $foreignReceiptLine->id,
                        'quantity' => '1',
                        'recognized_amount' =>
                            '100.00',
                    ]],
                ]
            )
            ->assertSessionHasErrors([
                'preferred_original_payment_id',
                'lines.0.commerce_post_sale_receipt_line_id',
            ]);
    }

    public function test_foreign_admin_cannot_read_or_resolve_case(): void
    {
        $fixture =
            $this->fixture('foreign-case');

        $other =
            Organization::query()
                ->create([
                    'name' =>
                        'Otra organización P8.5.3',
                    'slug' =>
                        'otra-organizacion-p853',
                    'active' => true,
                ]);

        $foreignAdmin =
            $this->user(
                $other,
                UserRole::Admin
            );

        $this->actingAs(
            $foreignAdmin
        )
            ->get(
                route(
                    'commerce-post-sale.resolutions.create',
                    $fixture['request']
                )
            )
            ->assertNotFound();

        $this->actingAs(
            $foreignAdmin
        )
            ->post(
                route(
                    'commerce-post-sale.resolutions.store',
                    $fixture['request']
                ),
                []
            )
            ->assertNotFound();
    }

    private function resolveHttp(
        array $fixture,
        int $receiptLineId,
        string $quantity,
        string $recognizedAmount,
        string $idempotencyKey
    ) {
        return $this->actingAs(
            $fixture['admin']
        )->post(
            route(
                'commerce-post-sale.resolutions.store',
                $fixture['request']
            ),
            [
                'outcome' =>
                    CommercePostSaleResolutionOutcome::Exchange
                        ->value,
                'reason' =>
                    'Se reconoce la cantidad recibida para continuar un cambio comercial.',
                'idempotency_key' =>
                    $idempotencyKey,
                'lines' => [[
                    'selected' => '1',
                    'commerce_post_sale_receipt_line_id' =>
                        $receiptLineId,
                    'quantity' =>
                        $quantity,
                    'recognized_amount' =>
                        $recognizedAmount,
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
                        'Cliente resolución P8.5.3 '
                        .$suffix,
                ]);

        $category =
            ProductCategory::withoutEvents(
                fn () =>
                    ProductCategory::query()
                        ->firstOrCreate(
                            [
                                'slug' =>
                                    'post-sale-resolution-http-tests',
                            ],
                            [
                                'name' =>
                                    'Posventa Resolución HTTP',
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
                                'P853-'
                                .Str::upper(
                                    Str::random(8)
                                ),
                            'name' =>
                                'Producto resolución P8.5.3 '
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
            'Precio HTTP P8.5.3.',
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
                        'Stock para resolución HTTP P8.5.3.',
                    idempotencyKey:
                        'p853:stock:'
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
                'Banco resolución P8.5.3 '
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
                        'p853:sale:'
                        .$suffix.':'
                        .$admin->id,
                    payments: [
                        new CommercePaymentData(
                            method:
                                CommercePaymentMethod::BankTransfer,
                            amountMinor:
                                20000,
                            reference:
                                'P853-'
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
            )->load([
                'lines',
                'payments',
            ]);

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
                        'El cliente solicita devolución de las dos unidades para evaluación.',
                    idempotencyKey:
                        'p853:request:'
                        .$suffix.':'
                        .$operator->id
                ),
                $operator
            )->load('lines');

        $requestLine =
            $postSale->lines->sole();

        $receipt =
            app(
                CommercePostSaleReceiptManager::class
            )->receive(
                new CommercePostSaleReceiptData(
                    commercePostSaleRequestId:
                        $postSale->id,
                    lines: [
                        new CommercePostSaleReceiptLineData(
                            commercePostSaleRequestLineId:
                                $requestLine->id,
                            quantity: '2',
                            condition:
                                InventoryCondition::Used,
                            destinationLocationId:
                                $location->id,
                            notes:
                                'Dos unidades físicamente recibidas.'
                        ),
                    ],
                    idempotencyKey:
                        'p853:receipt:'
                        .$suffix.':'
                        .$operator->id
                ),
                $operator
            )->load('lines');

        app(CurrentOrganization::class)
            ->forget($admin);

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
            'receipt' => $receipt,
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
