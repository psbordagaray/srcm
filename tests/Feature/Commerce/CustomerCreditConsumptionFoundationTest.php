<?php

namespace Tests\Feature\Commerce;

use App\Domain\Commerce\CommerceCheckoutData;
use App\Domain\Commerce\CommerceCheckoutManager;
use App\Domain\Commerce\CommercePaymentData;
use App\Domain\Commerce\CommercePostSaleCustomerCreditManager;
use App\Domain\Commerce\CommercePostSaleExchangeExecutionData;
use App\Domain\Commerce\CommercePostSaleExchangeExecutionLineData;
use App\Domain\Commerce\CommercePostSaleExchangeExecutionManager;
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
use App\Domain\Commerce\CustomerCreditBalanceReader;
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
use App\Enums\CommerceSaleStatus;
use App\Enums\FinancialAccountType;
use App\Enums\InventoryCondition;
use App\Enums\InventoryMovementType;
use App\Enums\UserRole;
use App\Models\BusinessParty;
use App\Models\CatalogProduct;
use App\Models\CustomerCreditConsumption;
use App\Models\CustomerCreditConsumptionAllocation;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductCategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerCreditConsumptionFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_schema_and_account_credit_contract_are_explicit(): void
    {
        $this->assertTrue(
            DB::getSchemaBuilder()->hasTable(
                'customer_credit_consumptions'
            )
        );

        $this->assertTrue(
            DB::getSchemaBuilder()->hasTable(
                'customer_credit_consumption_allocations'
            )
        );

        $this->assertFalse(
            CommercePaymentMethod::AccountCredit
                ->requiresReference()
        );

        $this->assertTrue(
            CommercePaymentMethod::BankTransfer
                ->requiresReference()
        );
    }

    public function test_checkout_consumes_fifo_across_both_credit_sources_and_leaves_derived_balance(): void
    {
        $context =
            $this->context('fifo');

        $standard =
            $this->grantStandardCredit(
                $context,
                10000,
                'fifo-standard'
            );

        $exchange =
            $this->grantExchangeCredit(
                $context,
                5000,
                'fifo-exchange'
            );

        $this->assertSame(
            15000,
            app(
                CustomerCreditBalanceReader::class
            )->balanceMinor(
                $context['organization']->id,
                $context['party']->id,
                'ARS'
            )
        );

        $product =
            $this->product(
                $context['admin'],
                'fifo-purchase',
                12000
            );

        $this->stock(
            $product,
            $context['location'],
            $context['operator'],
            'p856:stock:fifo-purchase'
        );

        $cashBefore =
            DB::table('cash_movements')->count();
        $externalBefore =
            DB::table(
                'financial_external_movements'
            )->count();

        $sale =
            app(
                CommerceCheckoutManager::class
            )->checkout(
                new CommerceCheckoutData(
                    currencyCode: 'ARS',
                    idempotencyKey:
                        'p856:sale:fifo-credit',
                    payments: [
                        new CommercePaymentData(
                            method:
                                CommercePaymentMethod::AccountCredit,
                            amountMinor: 12000
                        ),
                    ],
                    productLines: [
                        new CommerceProductLineData(
                            catalogProductId:
                                $product->id,
                            sourceLocationId:
                                $context['location']->id,
                            condition:
                                InventoryCondition::New,
                            quantity: '1',
                            unitPriceMinor: 12000
                        ),
                    ],
                    customerBusinessPartyId:
                        $context['party']->id
                ),
                $context['operator']
            )->load('payments');

        $this->assertSame(
            CommerceSaleStatus::Confirmed,
            $sale->status
        );

        $payment =
            $sale->payments->sole();

        $this->assertSame(
            CommercePaymentMethod::AccountCredit,
            $payment->method
        );

        $this->assertNull(
            $payment->financial_account_id
        );

        $consumption =
            CustomerCreditConsumption::query()
                ->with('allocations')
                ->sole();

        $this->assertSame(
            $consumption->public_id,
            $payment->reference
        );

        $this->assertSame(
            12000,
            $consumption->amount_minor
        );

        $allocations =
            $consumption->allocations;

        $this->assertCount(
            2,
            $allocations
        );

        $this->assertSame(
            $standard->id,
            $allocations[0]
                ->customer_credit_grant_id
        );

        $this->assertSame(
            10000,
            $allocations[0]
                ->amount_minor
        );

        $this->assertSame(
            $exchange->id,
            $allocations[1]
                ->commerce_post_sale_exchange_credit_grant_id
        );

        $this->assertSame(
            2000,
            $allocations[1]
                ->amount_minor
        );

        $this->assertSame(
            3000,
            app(
                CustomerCreditBalanceReader::class
            )->balanceMinor(
                $context['organization']->id,
                $context['party']->id,
                'ARS'
            )
        );

        $this->assertSame(
            $cashBefore,
            DB::table('cash_movements')->count()
        );

        $this->assertSame(
            $externalBefore,
            DB::table(
                'financial_external_movements'
            )->count()
        );
    }

    public function test_insufficient_credit_rolls_back_sale_stock_and_consumption_atomically(): void
    {
        $context =
            $this->context('insufficient');

        $this->grantStandardCredit(
            $context,
            5000,
            'insufficient-credit'
        );

        $product =
            $this->product(
                $context['admin'],
                'insufficient-purchase',
                10000
            );

        $this->stock(
            $product,
            $context['location'],
            $context['operator'],
            'p856:stock:insufficient-purchase'
        );

        $saleCount =
            DB::table('commerce_sales')->count();
        $movementCount =
            DB::table(
                'inventory_movements'
            )->count();

        try {
            app(
                CommerceCheckoutManager::class
            )->checkout(
                new CommerceCheckoutData(
                    currencyCode: 'ARS',
                    idempotencyKey:
                        'p856:sale:over-credit',
                    payments: [
                        new CommercePaymentData(
                            method:
                                CommercePaymentMethod::AccountCredit,
                            amountMinor: 10000
                        ),
                    ],
                    productLines: [
                        new CommerceProductLineData(
                            catalogProductId:
                                $product->id,
                            sourceLocationId:
                                $context['location']->id,
                            condition:
                                InventoryCondition::New,
                            quantity: '1',
                            unitPriceMinor: 10000
                        ),
                    ],
                    customerBusinessPartyId:
                        $context['party']->id
                ),
                $context['operator']
            );

            $this->fail(
                'El sobregiro de saldo debía fallar.'
            );
        } catch (DomainException $exception) {
            $this->assertStringContainsString(
                'saldo a favor suficiente',
                mb_strtolower(
                    $exception->getMessage()
                )
            );
        }

        $this->assertSame(
            $saleCount,
            DB::table('commerce_sales')->count()
        );

        $this->assertSame(
            $movementCount,
            DB::table(
                'inventory_movements'
            )->count()
        );

        $this->assertDatabaseCount(
            'customer_credit_consumptions',
            0
        );

        $this->assertDatabaseCount(
            'customer_credit_consumption_allocations',
            0
        );
    }

    public function test_database_rejects_forged_account_credit_payment_and_models_are_immutable(): void
    {
        $context =
            $this->context('guards');

        $this->grantStandardCredit(
            $context,
            10000,
            'guards-credit'
        );

        $product =
            $this->product(
                $context['admin'],
                'guards-purchase',
                10000
            );

        $this->stock(
            $product,
            $context['location'],
            $context['operator'],
            'p856:stock:guards-purchase'
        );

        $sale =
            app(
                CommerceCheckoutManager::class
            )->checkout(
                new CommerceCheckoutData(
                    currencyCode: 'ARS',
                    idempotencyKey:
                        'p856:sale:guards-credit',
                    payments: [
                        new CommercePaymentData(
                            method:
                                CommercePaymentMethod::AccountCredit,
                            amountMinor: 10000
                        ),
                    ],
                    productLines: [
                        new CommerceProductLineData(
                            catalogProductId:
                                $product->id,
                            sourceLocationId:
                                $context['location']->id,
                            condition:
                                InventoryCondition::New,
                            quantity: '1',
                            unitPriceMinor: 10000
                        ),
                    ],
                    customerBusinessPartyId:
                        $context['party']->id
                ),
                $context['operator']
            );

        $consumption =
            CustomerCreditConsumption::query()
                ->with('allocations')
                ->sole();

        try {
            DB::table(
                'commerce_payments'
            )->insert([
                'organization_id' =>
                    $context['organization']->id,
                'commerce_sale_id' =>
                    $sale->id,
                'financial_account_id' => null,
                'position' => 2,
                'method' =>
                    CommercePaymentMethod::AccountCredit
                        ->value,
                'amount_minor' => 1,
                'reference' => (string) Str::uuid(),
                'received_by_user_id' =>
                    $context['operator']->id,
                'paid_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->fail(
                'El pago AccountCredit sin consumo debía ser rechazado por la BD.'
            );
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        try {
            $consumption->amount_minor = 1;
            $consumption->save();

            $this->fail(
                'El consumo debía ser inmutable.'
            );
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        $allocation =
            $consumption
                ->allocations
                ->sole();

        try {
            $allocation->delete();

            $this->fail(
                'La allocation debía ser inmutable.'
            );
        } catch (DomainException) {
            $this->assertTrue(true);
        }
    }

    public function test_http_allows_split_credit_and_bank_without_cash_or_provider_effect(): void
    {
        Http::fake();

        $context =
            $this->context('http');

        $this->grantStandardCredit(
            $context,
            12000,
            'http-credit'
        );

        $product =
            $this->product(
                $context['admin'],
                'http-purchase',
                15000
            );

        $this->stock(
            $product,
            $context['location'],
            $context['operator'],
            'p856:stock:http-purchase'
        );

        $cashBefore =
            DB::table('cash_movements')->count();
        $externalBefore =
            DB::table(
                'financial_external_movements'
            )->count();

        $this->actingAs(
            $context['operator']
        )
            ->get(
                route(
                    'commerce-sales.create'
                )
            )
            ->assertOk()
            ->assertSee(
                'Crédito en cuenta'
            )
            ->assertSee(
                'customerCreditBalances',
                false
            );

        $this->actingAs(
            $context['operator']
        )
            ->post(
                route(
                    'commerce-sales.store'
                ),
                [
                    'currency_code' => 'ARS',
                    'customer_business_party_id' =>
                        $context['party']->id,
                    'product_lines' => [[
                        'catalog_product_id' =>
                            $product->id,
                        'source_location_id' =>
                            $context['location']->id,
                        'condition' =>
                            InventoryCondition::New
                                ->value,
                        'quantity' => '1',
                    ]],
                    'payments' => [
                        [
                            'method' =>
                                CommercePaymentMethod::AccountCredit
                                    ->value,
                            'amount' => '120,00',
                        ],
                        [
                            'method' =>
                                CommercePaymentMethod::BankTransfer
                                    ->value,
                            'amount' => '30,00',
                            'financial_account_id' =>
                                $context['bank']->id,
                            'reference' =>
                                'P856-HTTP-BANK',
                        ],
                    ],
                    'idempotency_key' =>
                        'service-ui:commerce-sale:'
                        .Str::uuid(),
                ]
            )
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount(
            'customer_credit_consumptions',
            1
        );

        $this->assertSame(
            $cashBefore,
            DB::table('cash_movements')->count()
        );

        $this->assertSame(
            $externalBefore,
            DB::table(
                'financial_external_movements'
            )->count()
        );

        Http::assertNothingSent();
    }

    public function test_http_rejects_account_credit_without_linked_customer(): void
    {
        $context =
            $this->context('anonymous');

        $product =
            $this->product(
                $context['admin'],
                'anonymous-purchase',
                10000
            );

        $this->stock(
            $product,
            $context['location'],
            $context['operator'],
            'p856:stock:anonymous'
        );

        $salesBefore =
            DB::table('commerce_sales')->count();

        $this->actingAs(
            $context['operator']
        )
            ->post(
                route(
                    'commerce-sales.store'
                ),
                [
                    'currency_code' => 'ARS',
                    'product_lines' => [[
                        'catalog_product_id' =>
                            $product->id,
                        'source_location_id' =>
                            $context['location']->id,
                        'condition' =>
                            InventoryCondition::New
                                ->value,
                        'quantity' => '1',
                    ]],
                    'payments' => [[
                        'method' =>
                            CommercePaymentMethod::AccountCredit
                                ->value,
                        'amount' => '100,00',
                    ]],
                    'idempotency_key' =>
                        'service-ui:commerce-sale:'
                        .Str::uuid(),
                ]
            )
            ->assertSessionHasErrors(
                'payments.0.method'
            );

        $this->assertSame(
            $salesBefore,
            DB::table('commerce_sales')->count()
        );

        $this->assertDatabaseCount(
            'customer_credit_consumptions',
            0
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function context(
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
                        'Cliente crédito P8.5.6 '
                        .$suffix,
                ]);

        $bank =
            app(
                FinancialAccountManager::class
            )->create(
                'Banco crédito P8.5.6 '
                .$suffix,
                FinancialAccountType::BankAccount,
                'ARS',
                $admin
            );

        app(CurrentOrganization::class)
            ->forget($admin);

        app(CurrentOrganization::class)
            ->forget($operator);

        return [
            'organization' =>
                $organization,
            'admin' => $admin,
            'operator' => $operator,
            'location' => $location,
            'party' => $party,
            'bank' => $bank,
        ];
    }

    private function grantStandardCredit(
        array $context,
        int $amountMinor,
        string $suffix
    ) {
        $product =
            $this->product(
                $context['admin'],
                'standard-'.$suffix,
                $amountMinor
            );

        $this->stock(
            $product,
            $context['location'],
            $context['operator'],
            'p856:stock:standard:'
            .$suffix
        );

        [$sale, $receipt] =
            $this->postSaleReceipt(
                $context,
                $product,
                CommercePostSaleIntent::Return,
                $amountMinor,
                'standard-'.$suffix
            );

        $resolution =
            app(
                CommercePostSaleResolutionManager::class
            )->resolve(
                new CommercePostSaleResolutionData(
                    commercePostSaleRequestId:
                        $receipt
                            ->request
                            ->id,
                    outcome:
                        CommercePostSaleResolutionOutcome::CustomerCredit,
                    lines: [
                        new CommercePostSaleResolutionLineData(
                            commercePostSaleReceiptLineId:
                                $receipt
                                    ->lines
                                    ->sole()
                                    ->id,
                            quantity: '1',
                            recognizedAmountMinor:
                                $amountMinor
                        ),
                    ],
                    reason:
                        'Se reconoce saldo a favor P8.5.6.',
                    idempotencyKey:
                        'p856:resolution:standard:'
                        .$suffix
                ),
                $context['admin']
            );

        return app(
            CommercePostSaleCustomerCreditManager::class
        )->grant(
            $resolution,
            'p856:grant:standard:'
            .$suffix,
            $context['admin']
        );
    }

    private function grantExchangeCredit(
        array $context,
        int $creditMinor,
        string $suffix
    ) {
        $recognized = 10000;
        $replacementPrice =
            $recognized - $creditMinor;

        $original =
            $this->product(
                $context['admin'],
                'exchange-original-'.$suffix,
                $recognized
            );

        $replacement =
            $this->product(
                $context['admin'],
                'exchange-replacement-'.$suffix,
                $replacementPrice
            );

        $this->stock(
            $original,
            $context['location'],
            $context['operator'],
            'p856:stock:exchange-original:'
            .$suffix
        );

        $this->stock(
            $replacement,
            $context['location'],
            $context['operator'],
            'p856:stock:exchange-replacement:'
            .$suffix
        );

        [, $receipt] =
            $this->postSaleReceipt(
                $context,
                $original,
                CommercePostSaleIntent::Exchange,
                $recognized,
                'exchange-'.$suffix
            );

        $resolution =
            app(
                CommercePostSaleResolutionManager::class
            )->resolve(
                new CommercePostSaleResolutionData(
                    commercePostSaleRequestId:
                        $receipt
                            ->request
                            ->id,
                    outcome:
                        CommercePostSaleResolutionOutcome::Exchange,
                    lines: [
                        new CommercePostSaleResolutionLineData(
                            commercePostSaleReceiptLineId:
                                $receipt
                                    ->lines
                                    ->sole()
                                    ->id,
                            quantity: '1',
                            recognizedAmountMinor:
                                $recognized
                        ),
                    ],
                    reason:
                        'Se reconoce cambio con diferencia a favor P8.5.6.',
                    idempotencyKey:
                        'p856:resolution:exchange:'
                        .$suffix
                ),
                $context['admin']
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
                            quantity: '1'
                        ),
                    ],
                    idempotencyKey:
                        'p856:selection:'
                        .$suffix
                ),
                $context['admin']
            )->load('lines');

        $execution =
            app(
                CommercePostSaleExchangeExecutionManager::class
            )->execute(
                $selection,
                new CommercePostSaleExchangeExecutionData(
                    lines: [
                        new CommercePostSaleExchangeExecutionLineData(
                            commercePostSaleExchangeSelectionLineId:
                                $selection
                                    ->lines
                                    ->sole()
                                    ->id,
                            sourceLocationId:
                                $context['location']->id,
                            condition:
                                InventoryCondition::New
                        ),
                    ],
                    payments: [],
                    idempotencyKey:
                        'p856:execution:'
                        .$suffix
                ),
                $context['operator']
            )->load('creditGrant');

        return $execution
            ->creditGrant;
    }

    /**
     * @return array{0:\App\Models\CommerceSale,1:\App\Models\CommercePostSaleReceipt}
     */
    private function postSaleReceipt(
        array $context,
        CatalogProduct $product,
        CommercePostSaleIntent $intent,
        int $priceMinor,
        string $suffix
    ): array {
        $sale =
            app(
                CommerceCheckoutManager::class
            )->checkout(
                new CommerceCheckoutData(
                    currencyCode: 'ARS',
                    idempotencyKey:
                        'p856:origin-sale:'
                        .$suffix,
                    payments: [
                        new CommercePaymentData(
                            method:
                                CommercePaymentMethod::BankTransfer,
                            amountMinor:
                                $priceMinor,
                            reference:
                                'P856-ORIGIN-'
                                .$suffix,
                            financialAccountId:
                                $context['bank']->id
                        ),
                    ],
                    productLines: [
                        new CommerceProductLineData(
                            catalogProductId:
                                $product->id,
                            sourceLocationId:
                                $context['location']->id,
                            condition:
                                InventoryCondition::New,
                            quantity: '1',
                            unitPriceMinor:
                                $priceMinor
                        ),
                    ],
                    customerBusinessPartyId:
                        $context['party']->id
                ),
                $context['operator']
            )->load('lines');

        $request =
            app(
                CommercePostSaleRequestManager::class
            )->create(
                new CommercePostSaleRequestData(
                    commerceSaleId:
                        $sale->id,
                    intent:
                        $intent,
                    lines: [
                        new CommercePostSaleRequestLineData(
                            commerceSaleLineId:
                                $sale
                                    ->lines
                                    ->sole()
                                    ->id,
                            quantity: '1'
                        ),
                    ],
                    reason:
                        'Caso fuente para crédito P8.5.6.',
                    idempotencyKey:
                        'p856:request:'
                        .$suffix
                ),
                $context['operator']
            )->load('lines');

        $receipt =
            app(
                CommercePostSaleReceiptManager::class
            )->receive(
                new CommercePostSaleReceiptData(
                    commercePostSaleRequestId:
                        $request->id,
                    lines: [
                        new CommercePostSaleReceiptLineData(
                            commercePostSaleRequestLineId:
                                $request
                                    ->lines
                                    ->sole()
                                    ->id,
                            quantity: '1',
                            condition:
                                InventoryCondition::Used,
                            destinationLocationId:
                                $context['location']->id
                        ),
                    ],
                    idempotencyKey:
                        'p856:receipt:'
                        .$suffix
                ),
                $context['operator']
            )->load([
                'lines',
                'request',
            ]);

        return [$sale, $receipt];
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
                                    'customer-credit-p856-tests',
                            ],
                            [
                                'name' =>
                                    'Customer Credit P8.5.6',
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
                                'P856-'
                                .Str::upper(
                                    Str::random(8)
                                ),
                            'name' =>
                                'Producto P8.5.6 '
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
            'Precio P8.5.6.',
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
                        'Stock P8.5.6.',
                    idempotencyKey:
                        $key,
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
