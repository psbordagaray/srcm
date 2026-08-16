<?php

namespace Tests\Feature\Commerce;

use App\Domain\Commerce\CommerceCheckoutData;
use App\Domain\Commerce\CommerceCheckoutManager;
use App\Domain\Commerce\CommercePaymentData;
use App\Domain\Commerce\CommercePostSaleExchangeSelectionData;
use App\Domain\Commerce\CommercePostSaleExchangeSelectionLineData;
use App\Domain\Commerce\CommercePostSaleExchangeSelectionManager;
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
use App\Models\CommercePostSaleExchangeExecution;
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

class CommercePostSaleExecutionHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_final_execution_routes_and_gates_are_explicit(): void
    {
        $routes = [
            'commerce-post-sale.exchange-executions.create' => [
                'GET',
                'can:execute-commerce-post-sale-exchange',
            ],
            'commerce-post-sale.exchange-executions.store' => [
                'POST',
                'can:execute-commerce-post-sale-exchange',
            ],
            'commerce-post-sale.external-refunds.submit' => [
                'POST',
                'can:dispatch-commerce-post-sale-external-refund',
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
            UserRole::Operator
                ->canExecuteCommercePostSaleExchange()
        );

        $this->assertTrue(
            UserRole::Operator
                ->canExecuteCommercePostSaleExternalRefund()
        );

        $this->assertFalse(
            UserRole::Viewer
                ->canExecuteCommercePostSaleExchange()
        );

        $this->assertFalse(
            UserRole::Viewer
                ->canExecuteCommercePostSaleExternalRefund()
        );
    }

    public function test_operator_executes_zero_difference_exchange_and_admin_selector_fails_segregation(): void
    {
        $context =
            $this->exchangeContext(
                'zero',
                10000
            );

        $selection =
            $context['selection'];

        $line =
            $selection->lines->sole();

        $url =
            route(
                'commerce-post-sale.exchange-executions.store',
                $selection
            );

        $create =
            route(
                'commerce-post-sale.exchange-executions.create',
                $selection
            );

        $payload = [
            'confirm_execution' => '1',
            'idempotency_key' =>
                'p855:http:zero:admin',
            'lines' => [[
                'commerce_post_sale_exchange_selection_line_id' =>
                    $line->id,
                'source_balance' =>
                    $context['location']->id
                    .'|'
                    .InventoryCondition::New->value,
            ]],
        ];

        $this->actingAs(
            $context['admin']
        )
            ->from($create)
            ->post(
                $url,
                $payload
            )
            ->assertRedirect($create)
            ->assertSessionHasErrors(
                'post_sale_execution'
            );

        $this->assertDatabaseCount(
            'commerce_post_sale_exchange_executions',
            0
        );

        $this->actingAs(
            $context['operator']
        )
            ->get($create)
            ->assertOk()
            ->assertSee(
                'Entregar reemplazo y resolver diferencia'
            )
            ->assertSee(
                $context['location']->name
            )
            ->assertSee(
                'Cambio sin diferencia monetaria'
            );

        $positiveSurface =
            $this->exchangeContext(
                'account-credit-surface',
                15000
            );

        $this->actingAs(
            $positiveSurface['operator']
        )
            ->get(
                route(
                    'commerce-post-sale.exchange-executions.create',
                    $positiveSurface['selection']
                )
            )
            ->assertOk()
            ->assertSee(
                'Crédito en cuenta'
            )
            ->assertSee(
                'Saldo a favor disponible'
            );

        $inventoryBefore =
            DB::table(
                'inventory_movements'
            )->count();

        $cashBefore =
            DB::table(
                'cash_movements'
            )->count();

        $payload[
            'idempotency_key'
        ] =
            'p855:http:zero:operator';

        $this->actingAs(
            $context['operator']
        )
            ->post(
                $url,
                $payload
            )
            ->assertRedirect(
                route(
                    'commerce-post-sale.show',
                    $context['request']
                )
            )
            ->assertSessionHasNoErrors();

        $execution =
            CommercePostSaleExchangeExecution::query()
                ->with([
                    'inventoryMovement',
                    'payments',
                    'creditGrant',
                ])
                ->sole();

        $this->assertSame(
            0,
            $execution
                ->difference_amount_minor
        );

        $this->assertCount(
            0,
            $execution->payments
        );

        $this->assertNull(
            $execution->creditGrant
        );

        $this->assertSame(
            $inventoryBefore + 1,
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

        $this->actingAs(
            $context['operator']
        )
            ->get(
                route(
                    'commerce-post-sale.show',
                    $context['request']
                )
            )
            ->assertOk()
            ->assertSee(
                'Cambio ejecutado'
            )
            ->assertSee(
                'InventoryMovement #'
                .$execution
                    ->inventory_movement_id
            );
    }

    public function test_positive_difference_records_exact_local_bank_payment_without_provider_http(): void
    {
        Http::fake();

        $context =
            $this->exchangeContext(
                'positive',
                15000
            );

        $selection =
            $context['selection'];

        $line =
            $selection->lines->sole();

        $externalBefore =
            DB::table(
                'financial_external_movements'
            )->count();

        $cashBefore =
            DB::table(
                'cash_movements'
            )->count();

        $this->actingAs(
            $context['operator']
        )
            ->post(
                route(
                    'commerce-post-sale.exchange-executions.store',
                    $selection
                ),
                [
                    'confirm_execution' =>
                        '1',
                    'idempotency_key' =>
                        'p855:http:positive',
                    'lines' => [[
                        'commerce_post_sale_exchange_selection_line_id' =>
                            $line->id,
                        'source_balance' =>
                            $context['location']->id
                            .'|'
                            .InventoryCondition::New->value,
                    ]],
                    'payments' => [[
                        'selected' => '1',
                        'method' =>
                            CommercePaymentMethod::BankTransfer
                                ->value,
                        'amount' =>
                            '50,00',
                        'financial_account_id' =>
                            $context['bank']->id,
                        'reference' =>
                            'DIF-P855',
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

        $execution =
            CommercePostSaleExchangeExecution::query()
                ->with('payments')
                ->sole();

        $this->assertSame(
            5000,
            $execution
                ->difference_amount_minor
        );

        $payment =
            $execution
                ->payments
                ->sole();

        $this->assertSame(
            5000,
            $payment
                ->amount_minor
        );

        $this->assertSame(
            CommercePaymentMethod::BankTransfer,
            $payment->method
        );

        $this->assertSame(
            $externalBefore,
            DB::table(
                'financial_external_movements'
            )->count()
        );

        $this->assertSame(
            $cashBefore,
            DB::table(
                'cash_movements'
            )->count()
        );

        Http::assertNothingSent();
    }


    public function test_http_consumes_account_credit_for_positive_exchange_difference_and_shows_fact(): void
    {
        $target =
            $this->exchangeContext(
                'account-credit-target',
                15000
            );

        $source =
            $this->exchangeContext(
                'account-credit-source',
                8000,
                $target['party']
            );

        $sourceLine =
            $source['selection']
                ->lines
                ->sole();

        $this->actingAs(
            $source['operator']
        )
            ->post(
                route(
                    'commerce-post-sale.exchange-executions.store',
                    $source['selection']
                ),
                [
                    'confirm_execution' =>
                        '1',
                    'idempotency_key' =>
                        'p857:http:credit-source',
                    'lines' => [[
                        'commerce_post_sale_exchange_selection_line_id' =>
                            $sourceLine->id,
                        'source_balance' =>
                            $source['location']->id
                            .'|'
                            .InventoryCondition::New->value,
                    ]],
                ]
            )
            ->assertRedirect(
                route(
                    'commerce-post-sale.show',
                    $source['request']
                )
            )
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas(
            'commerce_post_sale_exchange_credit_grants',
            [
                'business_party_id' =>
                    $target['party']->id,
                'amount_minor' =>
                    2000,
                'currency_code' =>
                    'ARS',
            ]
        );

        $targetLine =
            $target['selection']
                ->lines
                ->sole();

        $this->actingAs(
            $target['operator']
        )
            ->get(
                route(
                    'commerce-post-sale.exchange-executions.create',
                    $target['selection']
                )
            )
            ->assertOk()
            ->assertSee(
                'Crédito en cuenta'
            )
            ->assertSee(
                '20,00'
            );

        $this->actingAs(
            $target['operator']
        )
            ->post(
                route(
                    'commerce-post-sale.exchange-executions.store',
                    $target['selection']
                ),
                [
                    'confirm_execution' =>
                        '1',
                    'idempotency_key' =>
                        'p857:http:credit-target',
                    'lines' => [[
                        'commerce_post_sale_exchange_selection_line_id' =>
                            $targetLine->id,
                        'source_balance' =>
                            $target['location']->id
                            .'|'
                            .InventoryCondition::New->value,
                    ]],
                    'payments' => [
                        [
                            'selected' =>
                                '1',
                            'method' =>
                                CommercePaymentMethod::AccountCredit
                                    ->value,
                            'amount' =>
                                '20,00',
                        ],
                        [
                            'selected' =>
                                '1',
                            'method' =>
                                CommercePaymentMethod::BankTransfer
                                    ->value,
                            'amount' =>
                                '30,00',
                            'financial_account_id' =>
                                $target['bank']->id,
                            'reference' =>
                                'P857-HTTP-BANK',
                        ],
                    ],
                ]
            )
            ->assertRedirect(
                route(
                    'commerce-post-sale.show',
                    $target['request']
                )
            )
            ->assertSessionHasNoErrors();

        $executionId =
            DB::table(
                'commerce_post_sale_exchange_executions'
            )
                ->where(
                    'commerce_post_sale_exchange_selection_id',
                    $target['selection']->id
                )
                ->value('id');

        $this->assertNotNull(
            $executionId
        );

        $this->assertDatabaseHas(
            'customer_credit_consumptions',
            [
                'target_kind' =>
                    'exchange_payment',
                'target_id' =>
                    $executionId,
                'commerce_post_sale_exchange_execution_id' =>
                    $executionId,
                'business_party_id' =>
                    $target['party']->id,
                'amount_minor' =>
                    2000,
            ]
        );

        $this->assertDatabaseHas(
            'commerce_post_sale_exchange_payments',
            [
                'commerce_post_sale_exchange_execution_id' =>
                    $executionId,
                'method' =>
                    CommercePaymentMethod::BankTransfer
                        ->value,
                'amount_minor' =>
                    3000,
            ]
        );

        $this->actingAs(
            $target['operator']
        )
            ->get(
                route(
                    'commerce-post-sale.show',
                    $target['request']
                )
            )
            ->assertOk()
            ->assertSee(
                'Saldo a favor consumido'
            )
            ->assertSee(
                '20,00'
            );
    }

    public function test_execution_requires_explicit_confirmation_and_show_exposes_action_only_to_separate_operator(): void
    {
        $context =
            $this->exchangeContext(
                'confirm',
                10000
            );

        $selection =
            $context['selection'];

        $line =
            $selection->lines->sole();

        $this->actingAs(
            $context['operator']
        )
            ->get(
                route(
                    'commerce-post-sale.show',
                    $context['request']
                )
            )
            ->assertOk()
            ->assertSee(
                'Ejecutar entrega y diferencia'
            );

        $this->actingAs(
            $context['admin']
        )
            ->get(
                route(
                    'commerce-post-sale.show',
                    $context['request']
                )
            )
            ->assertOk()
            ->assertDontSee(
                'Ejecutar entrega y diferencia'
            );

        $this->actingAs(
            $context['operator']
        )
            ->from(
                route(
                    'commerce-post-sale.exchange-executions.create',
                    $selection
                )
            )
            ->post(
                route(
                    'commerce-post-sale.exchange-executions.store',
                    $selection
                ),
                [
                    'idempotency_key' =>
                        'p855:http:no-confirm',
                    'lines' => [[
                        'commerce_post_sale_exchange_selection_line_id' =>
                            $line->id,
                        'source_balance' =>
                            $context['location']->id
                            .'|'
                            .InventoryCondition::New->value,
                    ]],
                ]
            )
            ->assertSessionHasErrors(
                'confirm_execution'
            );

        $this->assertDatabaseCount(
            'commerce_post_sale_exchange_executions',
            0
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function exchangeContext(
        string $suffix,
        int $replacementPriceMinor,
        ?BusinessParty $party = null
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

        $location =
            InventoryLocation::query()
                ->forOrganization(
                    $organization->id
                )
                ->where('active', true)
                ->orderBy('id')
                ->firstOrFail();

        $party ??=
            BusinessParty::query()
                ->create([
                    'organization_id' =>
                        $organization->id,
                    'party_type' =>
                        BusinessParty::TYPE_PERSON,
                    'name' =>
                        'Cliente ejecución P8.5.5 '
                        .$suffix,
                ]);

        $original =
            $this->product(
                $admin,
                'original-'.$suffix,
                10000
            );

        $replacement =
            $this->product(
                $admin,
                'replacement-'.$suffix,
                $replacementPriceMinor
            );

        $this->stock(
            $original,
            $location,
            $operator,
            'p855:stock:original:'
            .$suffix
        );

        $this->stock(
            $replacement,
            $location,
            $operator,
            'p855:stock:replacement:'
            .$suffix
        );

        $bank =
            app(
                FinancialAccountManager::class
            )->create(
                'Banco ejecución P8.5.5 '
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
                    currencyCode:
                        'ARS',
                    idempotencyKey:
                        'p855:sale:'
                        .$suffix,
                    payments: [
                        new CommercePaymentData(
                            method:
                                CommercePaymentMethod::BankTransfer,
                            amountMinor:
                                10000,
                            reference:
                                'P855-'
                                .$suffix,
                            financialAccountId:
                                $bank->id
                        ),
                    ],
                    productLines: [
                        new CommerceProductLineData(
                            catalogProductId:
                                $original->id,
                            sourceLocationId:
                                $location->id,
                            condition:
                                InventoryCondition::New,
                            quantity:
                                '1',
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

        $postSale =
            app(
                CommercePostSaleRequestManager::class
            )->create(
                new CommercePostSaleRequestData(
                    commerceSaleId:
                        $sale->id,
                    intent:
                        CommercePostSaleIntent::Exchange,
                    lines: [
                        new CommercePostSaleRequestLineData(
                            commerceSaleLineId:
                                $sale->lines
                                    ->sole()
                                    ->id,
                            quantity:
                                '1'
                        ),
                    ],
                    reason:
                        'El cliente solicita cambio de una unidad.',
                    idempotencyKey:
                        'p855:request:'
                        .$suffix
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
                            quantity:
                                '1',
                            condition:
                                InventoryCondition::Used,
                            destinationLocationId:
                                $location->id
                        ),
                    ],
                    idempotencyKey:
                        'p855:receipt:'
                        .$suffix
                ),
                $operator
            )->load('lines');

        $resolution =
            app(
                CommercePostSaleResolutionManager::class
            )->resolve(
                new CommercePostSaleResolutionData(
                    commercePostSaleRequestId:
                        $postSale->id,
                    outcome:
                        CommercePostSaleResolutionOutcome::Exchange,
                    lines: [
                        new CommercePostSaleResolutionLineData(
                            commercePostSaleReceiptLineId:
                                $receipt->lines
                                    ->sole()
                                    ->id,
                            quantity:
                                '1',
                            recognizedAmountMinor:
                                10000
                        ),
                    ],
                    reason:
                        'Se reconoce el valor original para ejecutar un cambio.',
                    idempotencyKey:
                        'p855:resolution:'
                        .$suffix
                ),
                $admin
            );

        $selection =
            app(
                CommercePostSaleExchangeSelectionManager::class
            )->select(
                $resolution,
                new CommercePostSaleExchangeSelectionData(
                    lines: [
                        new CommercePostSaleExchangeSelectionLineData(
                            catalogProductId:
                                $replacement->id,
                            quantity:
                                '1'
                        ),
                    ],
                    idempotencyKey:
                        'p855:selection:'
                        .$suffix
                ),
                $admin
            )->load([
                'lines.product',
                'resolution.request',
            ]);

        app(CurrentOrganization::class)
            ->forget($admin);

        app(CurrentOrganization::class)
            ->forget($operator);

        return [
            'organization' =>
                $organization,
            'admin' =>
                $admin,
            'operator' =>
                $operator,
            'location' =>
                $location,
            'party' =>
                $party,
            'bank' =>
                $bank,
            'sale' =>
                $sale,
            'request' =>
                $postSale,
            'receipt' =>
                $receipt,
            'resolution' =>
                $resolution,
            'selection' =>
                $selection,
        ];
    }

    private function product(
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
                                    'post-sale-execution-http-tests',
                            ],
                            [
                                'name' =>
                                    'Posventa Ejecución HTTP',
                                'active' =>
                                    true,
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
                                'P855-'
                                .Str::upper(
                                    Str::random(8)
                                ),
                            'name' =>
                                'Producto P8.5.5 '
                                .$suffix,
                            'active' =>
                                true,
                        ])
                        ->refresh()
            );

        app(
            OrganizationProductPriceManager::class
        )->set(
            $product,
            'ARS',
            $priceMinor,
            'Precio P8.5.5.',
            $admin
        );

        return $product;
    }

    private function stock(
        CatalogProduct $product,
        InventoryLocation $location,
        User $operator,
        string $key
    ): void {
        $movement =
            app(
                InventoryMovementCreator::class
            )->create(
                new InventoryMovementDraftData(
                    type:
                        InventoryMovementType::Receipt,
                    effectiveAt:
                        CarbonImmutable::now(),
                    reason:
                        'Stock P8.5.5.',
                    idempotencyKey:
                        $key,
                    lines: [
                        new InventoryMovementLineData(
                            catalogProductId:
                                $product->id,
                            condition:
                                InventoryCondition::New,
                            enteredQuantity:
                                '1',
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
            $movement,
            $operator
        );
    }

    private function user(
        Organization $organization,
        UserRole $role
    ): User {
        $user =
            User::factory()->create([
                'role' =>
                    $role,
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
                    'role' =>
                        $role,
                    'active' =>
                        true,
                ]
            );

        app(CurrentOrganization::class)
            ->forget($user);

        return $user->refresh();
    }
}
