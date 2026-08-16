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
use App\Domain\Commerce\CommercePostSaleResolutionData;
use App\Domain\Commerce\CommercePostSaleResolutionLineData;
use App\Domain\Commerce\CommercePostSaleResolutionManager;
use App\Domain\Commerce\CommerceProductLineData;
use App\Domain\Commerce\OrganizationProductPriceManager;
use App\Domain\Finance\CashRegisterManager;
use App\Domain\Finance\CashRegisterSessionManager;
use App\Domain\Finance\FinancialAccountManager;
use App\Domain\Inventory\InventoryMovementConfirmer;
use App\Domain\Inventory\InventoryMovementCreator;
use App\Domain\Inventory\InventoryMovementDraftData;
use App\Domain\Inventory\InventoryMovementLineData;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CashMovementType;
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
use App\Models\CommercePostSaleExchangeSelection;
use App\Models\CommercePostSaleResolution;
use App\Models\CustomerCreditGrant;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductCategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CommercePostSaleOutcomeMaterializationHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_outcome_routes_and_gates_are_explicit_and_segregated(): void
    {
        $routes = [
            'commerce-post-sale.customer-credits.store' => [
                'POST',
                'can:materialize-commerce-post-sale-customer-credit',
            ],
            'commerce-post-sale.cash-refunds.create' => [
                'GET',
                'can:execute-commerce-post-sale-cash-refund',
            ],
            'commerce-post-sale.cash-refunds.store' => [
                'POST',
                'can:execute-commerce-post-sale-cash-refund',
            ],
            'commerce-post-sale.external-refunds.store' => [
                'POST',
                'can:execute-commerce-post-sale-external-refund',
            ],
            'commerce-post-sale.exchange-selections.create' => [
                'GET',
                'can:select-commerce-post-sale-exchange',
            ],
            'commerce-post-sale.exchange-selections.store' => [
                'POST',
                'can:select-commerce-post-sale-exchange',
            ],
        ];

        foreach (
            $routes
            as $name => [$method, $ability]
        ) {
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
                $ability,
                $route->gatherMiddleware()
            );
        }

        $this->assertTrue(
            UserRole::Admin
                ->canMaterializeCommercePostSaleCustomerCredit()
        );

        $this->assertFalse(
            UserRole::Operator
                ->canMaterializeCommercePostSaleCustomerCredit()
        );

        $this->assertTrue(
            UserRole::Operator
                ->canExecuteCommercePostSaleCashRefund()
        );

        $this->assertTrue(
            UserRole::Operator
                ->canExecuteCommercePostSaleExternalRefund()
        );

        $this->assertTrue(
            UserRole::Admin
                ->canResolveCommercePostSale()
        );

        $this->assertFalse(
            UserRole::Operator
                ->canResolveCommercePostSale()
        );
    }

    public function test_http_refund_resolution_requires_explicit_original_payment(): void
    {
        $context =
            $this->baseContext(
                'refund-required-payment',
                'bank'
            );

        $receiptLine =
            $context['receipt']
                ->lines
                ->sole();

        $create =
            route(
                'commerce-post-sale.resolutions.create',
                $context['request']
            );

        $this->actingAs(
            $context['admin']
        )
            ->from($create)
            ->post(
                route(
                    'commerce-post-sale.resolutions.store',
                    $context['request']
                ),
                [
                    'outcome' =>
                        CommercePostSaleResolutionOutcome::Refund
                            ->value,
                    'reason' =>
                        'Se reconoce el reintegro y debe quedar ligado a un pago original ejecutable.',
                    'idempotency_key' =>
                        'p854:refund:missing-payment',
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
            ->assertRedirect($create)
            ->assertSessionHasErrors(
                'preferred_original_payment_id'
            );

        $this->assertDatabaseCount(
            'commerce_post_sale_resolutions',
            0
        );
    }

    public function test_admin_materializes_customer_credit_once_without_cash_effect(): void
    {
        $context =
            $this->baseContext(
                'credit',
                'bank'
            );

        $resolution =
            $this->resolve(
                $context,
                CommercePostSaleResolutionOutcome::CustomerCredit,
                false,
                'p854:credit:resolution'
            );

        $cashBefore =
            DB::table(
                'cash_movements'
            )->count();

        $response =
            $this->actingAs(
                $context['admin']
            )->post(
                route(
                    'commerce-post-sale.customer-credits.store',
                    $resolution
                ),
                [
                    'idempotency_key' =>
                        'p854:credit:grant',
                ]
            );

        $grant =
            CustomerCreditGrant::query()
                ->sole();

        $response
            ->assertRedirect(
                route(
                    'commerce-post-sale.show',
                    $context['request']
                )
            )
            ->assertSessionHasNoErrors();

        $this->assertSame(
            10000,
            $grant->amount_minor
        );

        $this->assertSame(
            $context['party']->id,
            $grant->business_party_id
        );

        $this->assertSame(
            $cashBefore,
            DB::table(
                'cash_movements'
            )->count()
        );

        $this->actingAs(
            $context['admin']
        )
            ->post(
                route(
                    'commerce-post-sale.customer-credits.store',
                    $resolution
                ),
                [
                    'idempotency_key' =>
                        'p854:credit:grant',
                ]
            )
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount(
            'customer_credit_grants',
            1
        );
    }

    public function test_cash_refund_http_requires_separate_executor_and_records_cash_out(): void
    {
        $context =
            $this->baseContext(
                'cash-refund',
                'cash'
            );

        $resolution =
            $this->resolve(
                $context,
                CommercePostSaleResolutionOutcome::Refund,
                true,
                'p854:cash:resolution'
            );

        $create =
            route(
                'commerce-post-sale.cash-refunds.create',
                $resolution
            );

        $this->actingAs(
            $context['operator']
        )
            ->get($create)
            ->assertOk()
            ->assertSee(
                'Ejecutar salida de caja'
            );

        $this->actingAs(
            $context['admin']
        )
            ->from($create)
            ->post(
                route(
                    'commerce-post-sale.cash-refunds.store',
                    $resolution
                ),
                [
                    'idempotency_key' =>
                        'p854:cash:self',
                ]
            )
            ->assertRedirect($create)
            ->assertSessionHasErrors(
                'post_sale_outcome'
            );

        $this->assertDatabaseCount(
            'commerce_post_sale_cash_refund_executions',
            0
        );

        $this->actingAs(
            $context['operator']
        )
            ->post(
                route(
                    'commerce-post-sale.cash-refunds.store',
                    $resolution
                ),
                [
                    'idempotency_key' =>
                        'p854:cash:execute',
                    'execution_reference' =>
                        'MOSTRADOR-P854',
                    'execution_note' =>
                        'Dinero entregado al cliente.',
                ]
            )
            ->assertRedirect(
                route(
                    'commerce-post-sale.show',
                    $context['request']
                )
            )
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount(
            'commerce_post_sale_cash_refund_executions',
            1
        );

        $this->assertSame(
            1,
            DB::table('cash_movements')
                ->where(
                    'type',
                    CashMovementType::PostSaleRefund
                        ->value
                )
                ->count()
        );
    }

    public function test_external_refund_instruction_http_fails_closed_without_external_operation_and_never_calls_provider(): void
    {
        Http::fake();

        $context =
            $this->baseContext(
                'external-refund',
                'bank'
            );

        $resolution =
            $this->resolve(
                $context,
                CommercePostSaleResolutionOutcome::Refund,
                true,
                'p854:external:resolution'
            );

        $show =
            route(
                'commerce-post-sale.show',
                $context['request']
            );

        $this->actingAs(
            $context['operator']
        )
            ->from($show)
            ->post(
                route(
                    'commerce-post-sale.external-refunds.store',
                    $resolution
                ),
                [
                    'idempotency_key' =>
                        'p854:external:instruction',
                ]
            )
            ->assertRedirect($show)
            ->assertSessionHasErrors(
                'post_sale_outcome'
            );

        $this->assertDatabaseCount(
            'commerce_post_sale_external_refund_instructions',
            0
        );

        Http::assertNothingSent();
    }

    public function test_admin_selects_server_priced_exchange_without_stock_or_money_execution(): void
    {
        $context =
            $this->baseContext(
                'exchange',
                'bank'
            );

        $resolution =
            $this->resolve(
                $context,
                CommercePostSaleResolutionOutcome::Exchange,
                false,
                'p854:exchange:resolution'
            );

        $replacement =
            $this->product(
                $context['organization'],
                $context['admin'],
                'replacement',
                15000
            );

        $inventoryBefore =
            DB::table(
                'inventory_movements'
            )->count();

        $cashBefore =
            DB::table(
                'cash_movements'
            )->count();

        $this->actingAs(
            $context['admin']
        )
            ->get(
                route(
                    'commerce-post-sale.exchange-selections.create',
                    $resolution
                )
            )
            ->assertOk()
            ->assertSee(
                $replacement->name
            )
            ->assertSee(
                '150,00'
            );

        $this->actingAs(
            $context['admin']
        )
            ->post(
                route(
                    'commerce-post-sale.exchange-selections.store',
                    $resolution
                ),
                [
                    'idempotency_key' =>
                        'p854:exchange:selection',
                    'notes' =>
                        'Reemplazo elegido por el cliente.',
                    'lines' => [[
                        'selected' => '1',
                        'catalog_product_id' =>
                            $replacement->id,
                        'quantity' => '1',
                    ]],
                ]
            )
            ->assertRedirect(
                route(
                    'commerce-post-sale.show',
                    $context['request']
                )
            )
            ->assertSessionHasNoErrors();

        $selection =
            CommercePostSaleExchangeSelection::query()
                ->with('lines')
                ->sole();

        $this->assertSame(
            10000,
            $selection
                ->recognized_amount_minor
        );

        $this->assertSame(
            15000,
            $selection
                ->replacementAmountMinor()
        );

        $this->assertSame(
            5000,
            $selection
                ->differenceAmountMinor()
        );

        $this->assertSame(
            $inventoryBefore,
            DB::table(
                'inventory_movements'
            )->count()
        );

        $this->assertSame(
            $cashBefore,
            DB::table(
                'cash_movements'
            )->count()
        );

        $this->assertDatabaseCount(
            'commerce_post_sale_exchange_executions',
            0
        );
    }

    public function test_foreign_resolution_is_hidden_from_all_outcome_actions(): void
    {
        $context =
            $this->baseContext(
                'foreign',
                'bank'
            );

        $resolution =
            $this->resolve(
                $context,
                CommercePostSaleResolutionOutcome::CustomerCredit,
                false,
                'p854:foreign:resolution'
            );

        $other =
            Organization::query()
                ->create([
                    'name' =>
                        'Otra organización P8.5.4',
                    'slug' =>
                        'otra-organizacion-p854',
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
            ->post(
                route(
                    'commerce-post-sale.customer-credits.store',
                    $resolution
                ),
                [
                    'idempotency_key' =>
                        'p854:foreign',
                ]
            )
            ->assertNotFound();

        $this->assertDatabaseCount(
            'customer_credit_grants',
            0
        );
    }

    private function resolve(
        array $context,
        CommercePostSaleResolutionOutcome $outcome,
        bool $preferredPayment,
        string $idempotencyKey
    ): CommercePostSaleResolution {
        $receiptLine =
            $context['receipt']
                ->lines
                ->sole();

        return app(
            CommercePostSaleResolutionManager::class
        )->resolve(
            new CommercePostSaleResolutionData(
                commercePostSaleRequestId:
                    $context['request']->id,
                outcome: $outcome,
                lines: [
                    new CommercePostSaleResolutionLineData(
                        commercePostSaleReceiptLineId:
                            $receiptLine->id,
                        quantity: '1',
                        recognizedAmountMinor:
                            10000
                    ),
                ],
                reason:
                    'Resolución económica de prueba P8.5.4 con valor original reconocido.',
                idempotencyKey:
                    $idempotencyKey,
                preferredOriginalPaymentId:
                    $preferredPayment
                        ? $context['payment']->id
                        : null
            ),
            $context['admin']
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function baseContext(
        string $suffix,
        string $paymentMode
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
                        'Cliente outcomes P8.5.4 '
                        .$suffix,
                ]);

        $product =
            $this->product(
                $organization,
                $admin,
                'original-'.$suffix,
                10000
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
                        'Stock previo P8.5.4.',
                    idempotencyKey:
                        'p854:stock:'
                        .$suffix.':'
                        .$operator->id,
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
                $operator
            );

        app(
            InventoryMovementConfirmer::class
        )->confirm(
            $stock,
            $operator
        );

        $session = null;

        if ($paymentMode === 'cash') {
            $account =
                app(
                    FinancialAccountManager::class
                )->create(
                    'Caja outcomes P8.5.4 '
                    .$suffix,
                    FinancialAccountType::CashBox,
                    'ARS',
                    $admin
                );

            $register =
                app(
                    CashRegisterManager::class
                )->create(
                    'Caja outcomes P8.5.4 '
                    .$suffix,
                    $account,
                    $admin
                );

            $session =
                app(
                    CashRegisterSessionManager::class
                )->open(
                    $register,
                    50000,
                    'p854:session:'
                    .$suffix.':'
                    .$operator->id,
                    $operator
                );

            $paymentData =
                new CommercePaymentData(
                    method:
                        CommercePaymentMethod::Cash,
                    amountMinor: 20000,
                    financialAccountId:
                        $account->id,
                    tenderedAmountMinor:
                        20000
                );
        } else {
            $account =
                app(
                    FinancialAccountManager::class
                )->create(
                    'Banco outcomes P8.5.4 '
                    .$suffix,
                    FinancialAccountType::BankAccount,
                    'ARS',
                    $admin
                );

            $paymentData =
                new CommercePaymentData(
                    method:
                        CommercePaymentMethod::BankTransfer,
                    amountMinor: 20000,
                    reference:
                        'P854-'
                        .$suffix,
                    financialAccountId:
                        $account->id
                );
        }

        $sale =
            app(
                CommerceCheckoutManager::class
            )->checkout(
                new CommerceCheckoutData(
                    currencyCode: 'ARS',
                    idempotencyKey:
                        'p854:sale:'
                        .$suffix.':'
                        .$operator->id,
                    payments: [
                        $paymentData,
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
                $operator
            )->load([
                'lines',
                'payments',
            ]);

        $payment =
            $sale->payments->sole();

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
                                $sale->lines
                                    ->sole()
                                    ->id,
                            quantity: '2'
                        ),
                    ],
                    reason:
                        'El cliente solicita posventa para materialización de outcome.',
                    idempotencyKey:
                        'p854:request:'
                        .$suffix.':'
                        .$operator->id
                ),
                $operator
            )->load('lines');

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
                                $postSale->lines
                                    ->sole()
                                    ->id,
                            quantity: '2',
                            condition:
                                InventoryCondition::Used,
                            destinationLocationId:
                                $location->id
                        ),
                    ],
                    idempotencyKey:
                        'p854:receipt:'
                        .$suffix.':'
                        .$operator->id
                ),
                $operator
            )->load('lines');

        foreach ([
            $admin,
            $operator,
            $viewer,
        ] as $user) {
            app(
                CurrentOrganization::class
            )->forget($user);
        }

        return [
            'organization' =>
                $organization,
            'admin' => $admin,
            'operator' => $operator,
            'viewer' => $viewer,
            'location' => $location,
            'party' => $party,
            'product' => $product,
            'account' => $account,
            'session' => $session,
            'sale' => $sale,
            'payment' => $payment,
            'request' => $postSale,
            'receipt' => $receipt,
        ];
    }

    private function product(
        Organization $organization,
        User $admin,
        string $suffix,
        int $priceMinor
    ): CatalogProduct {
        $category =
            ProductCategory::withoutEvents(
                fn () =>
                    ProductCategory::query()
                        ->firstOrCreate(
                            [
                                'slug' =>
                                    'post-sale-outcome-http-tests',
                            ],
                            [
                                'name' =>
                                    'Posventa Outcomes HTTP',
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
                                'P854-'
                                .Str::upper(
                                    Str::random(8)
                                ),
                            'name' =>
                                'Producto P8.5.4 '
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
            $priceMinor,
            'Precio HTTP P8.5.4.',
            $admin
        );

        return $product;
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
