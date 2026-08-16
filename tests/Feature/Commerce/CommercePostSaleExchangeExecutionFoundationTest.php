<?php

namespace Tests\Feature\Commerce;

use App\Domain\Commerce\CommerceCheckoutData;
use App\Domain\Commerce\CommerceCheckoutManager;
use App\Domain\Commerce\CommercePaymentData;
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
use App\Domain\Commerce\OrganizationProductPriceManager;
use App\Domain\Finance\CashRegisterManager;
use App\Domain\Finance\CashRegisterSessionManager;
use App\Domain\Finance\FinancialAccountManager;
use App\Domain\Inventory\InventoryMovementConfirmer;
use App\Domain\Inventory\InventoryMovementCreator;
use App\Domain\Inventory\InventoryMovementDraftData;
use App\Domain\Inventory\InventoryMovementLineData;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CashMovementDirection;
use App\Enums\CashMovementType;
use App\Enums\CommercePaymentMethod;
use App\Enums\CommercePostSaleIntent;
use App\Enums\CommercePostSaleResolutionOutcome;
use App\Enums\FinancialAccountType;
use App\Enums\InventoryCondition;
use App\Enums\InventoryMovementType;
use App\Enums\UserRole;
use App\Models\BusinessParty;
use App\Models\CatalogProduct;
use App\Models\CommercePostSaleExchangeCreditGrant;
use App\Models\CommercePostSaleExchangeExecution;
use App\Models\CommercePostSaleExchangeExecutionLine;
use App\Models\CommercePostSaleExchangeSelection;
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
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class CommercePostSaleExchangeExecutionFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_schema_permissions_and_cash_type_are_explicit(): void
    {
        $this->assertTrue(
            Schema::hasColumns(
                'commerce_post_sale_exchange_executions',
                [
                    'organization_id',
                    'public_id',
                    'commerce_post_sale_exchange_selection_id',
                    'inventory_movement_id',
                    'recognized_amount_minor',
                    'replacement_amount_minor',
                    'difference_amount_minor',
                    'currency_code',
                    'executed_by_user_id',
                    'executed_at',
                    'idempotency_key',
                    'fingerprint',
                ]
            )
        );

        $this->assertTrue(
            Schema::hasColumns(
                'commerce_post_sale_exchange_execution_lines',
                [
                    'commerce_post_sale_exchange_execution_id',
                    'commerce_post_sale_exchange_selection_line_id',
                    'inventory_movement_line_id',
                    'source_location_id',
                    'condition',
                ]
            )
        );

        $this->assertTrue(
            Schema::hasColumns(
                'commerce_post_sale_exchange_payments',
                [
                    'commerce_post_sale_exchange_execution_id',
                    'financial_account_id',
                    'method',
                    'amount_minor',
                    'received_by_user_id',
                    'fingerprint',
                ]
            )
        );

        $this->assertTrue(
            Schema::hasColumns(
                'commerce_post_sale_exchange_credit_grants',
                [
                    'commerce_post_sale_exchange_execution_id',
                    'business_party_id',
                    'amount_minor',
                    'currency_code',
                    'granted_by_user_id',
                ]
            )
        );

        $this->assertTrue(
            Schema::hasColumn(
                'cash_movements',
                'post_sale_exchange_payment_id'
            )
        );

        $this->assertTrue(
            UserRole::Admin
                ->canExecuteCommercePostSaleExchange()
        );

        $this->assertTrue(
            UserRole::Operator
                ->canExecuteCommercePostSaleExchange()
        );

        $this->assertFalse(
            UserRole::Viewer
                ->canExecuteCommercePostSaleExchange()
        );

        $this->assertSame(
            'post_sale_exchange_difference',
            CashMovementType::PostSaleExchangeDifference
                ->value
        );
    }

    public function test_zero_difference_issues_replacement_without_money_and_is_idempotent(): void
    {
        $context =
            $this->context(
                'zero',
                replacementPriceMinor: 7000
            );

        $inventoryBefore =
            DB::table('inventory_movements')
                ->count();

        $manager = app(
            CommercePostSaleExchangeExecutionManager::class
        );

        $data =
            $this->executionData(
                $context,
                'p845:zero'
            );

        $first =
            $manager->execute(
                $context['selection'],
                $data,
                $context['operator']
            );

        $second =
            $manager->execute(
                $context['selection'],
                $data,
                $context['operator']
            );

        $this->assertSame(
            $first->id,
            $second->id
        );

        $this->assertSame(
            0,
            $first->difference_amount_minor
        );

        $this->assertSame(
            $inventoryBefore + 1,
            DB::table('inventory_movements')
                ->count()
        );

        $this->assertSame(
            InventoryMovementType::Issue,
            $first->inventoryMovement->type
        );

        $executionLine =
            $first->lines->sole();

        $this->assertSame(
            $first->inventory_movement_id,
            (int) DB::table(
                'inventory_movement_lines'
            )
                ->where(
                    'id',
                    $executionLine
                        ->inventory_movement_line_id
                )
                ->value(
                    'inventory_movement_id'
                )
        );

        $this->assertCount(
            0,
            $first->payments
        );

        $this->assertNull(
            $first->creditGrant
        );

        $this->assertDatabaseCount(
            'commerce_post_sale_exchange_payments',
            0
        );

        $this->assertDatabaseCount(
            'commerce_post_sale_exchange_credit_grants',
            0
        );
    }

    public function test_positive_difference_supports_split_cash_and_bank_without_external_ledger(): void
    {
        $context =
            $this->context(
                'positive',
                replacementPriceMinor: 9000
            );

        $cashAccount = app(
            FinancialAccountManager::class
        )->create(
            'Caja P8.4.5 positive',
            FinancialAccountType::CashBox,
            'ARS',
            $context['admin']
        );

        $register = app(
            CashRegisterManager::class
        )->create(
            'Caja P8.4.5 positive',
            $cashAccount,
            $context['admin']
        );

        app(CurrentOrganization::class)
            ->forget($context['operator']);

        app(
            CashRegisterSessionManager::class
        )->open(
            $register,
            50000,
            'p845:cash-session:positive',
            $context['operator']
        );

        $bank = app(
            FinancialAccountManager::class
        )->create(
            'Banco P8.4.5 positive',
            FinancialAccountType::BankAccount,
            'ARS',
            $context['admin']
        );

        $externalBefore =
            DB::table(
                'financial_external_movements'
            )->count();

        $execution = app(
            CommercePostSaleExchangeExecutionManager::class
        )->execute(
            $context['selection'],
            $this->executionData(
                $context,
                'p845:positive',
                payments: [
                    new CommercePaymentData(
                        method:
                            CommercePaymentMethod::Cash,
                        amountMinor: 1000,
                        financialAccountId:
                            $cashAccount->id,
                        tenderedAmountMinor:
                            1500
                    ),
                    new CommercePaymentData(
                        method:
                            CommercePaymentMethod::BankTransfer,
                        amountMinor: 1000,
                        reference:
                            'TR-P845-POSITIVE',
                        financialAccountId:
                            $bank->id
                    ),
                ]
            ),
            $context['operator']
        );

        $this->assertSame(
            2000,
            $execution->difference_amount_minor
        );

        $this->assertCount(
            2,
            $execution->payments
        );

        $this->assertSame(
            2000,
            (int) $execution->payments
                ->sum('amount_minor')
        );

        $cashPayment =
            $execution->payments
                ->first(
                    fn ($payment): bool =>
                        $payment->method
                            === CommercePaymentMethod::Cash
                );

        $this->assertNotNull(
            $cashPayment
        );

        $this->assertSame(
            1500,
            $cashPayment
                ->tendered_amount_minor
        );

        $this->assertSame(
            500,
            $cashPayment
                ->change_amount_minor
        );

        $cashMovement =
            $cashPayment->cashMovement;

        $this->assertNotNull(
            $cashMovement
        );

        $this->assertSame(
            CashMovementDirection::In,
            $cashMovement->direction
        );

        $this->assertSame(
            CashMovementType::PostSaleExchangeDifference,
            $cashMovement->type
        );

        $this->assertSame(
            1000,
            $cashMovement->amount_minor
        );

        $this->assertSame(
            $externalBefore,
            DB::table(
                'financial_external_movements'
            )->count()
        );

        $this->assertNull(
            $execution->creditGrant
        );
    }

    public function test_negative_difference_grants_exact_customer_credit_without_cash(): void
    {
        $context =
            $this->context(
                'negative',
                replacementPriceMinor: 5000
            );

        $cashBefore =
            DB::table('cash_movements')
                ->count();

        $execution = app(
            CommercePostSaleExchangeExecutionManager::class
        )->execute(
            $context['selection'],
            $this->executionData(
                $context,
                'p845:negative'
            ),
            $context['operator']
        );

        $this->assertSame(
            -2000,
            $execution->difference_amount_minor
        );

        $credit =
            $execution->creditGrant;

        $this->assertInstanceOf(
            CommercePostSaleExchangeCreditGrant::class,
            $credit
        );

        $this->assertSame(
            2000,
            $credit->amount_minor
        );

        $this->assertSame(
            $context['party']->id,
            $credit->business_party_id
        );

        $this->assertCount(
            0,
            $execution->payments
        );

        $this->assertSame(
            $cashBefore,
            DB::table('cash_movements')
                ->count()
        );
    }

    public function test_segregation_second_operation_and_account_credit_fail_closed(): void
    {
        $context =
            $this->context(
                'guards',
                replacementPriceMinor: 9000
            );

        $manager = app(
            CommercePostSaleExchangeExecutionManager::class
        );

        $bank = app(
            FinancialAccountManager::class
        )->create(
            'Banco P8.4.5 guards',
            FinancialAccountType::BankAccount,
            'ARS',
            $context['admin']
        );

        $valid =
            $this->executionData(
                $context,
                'p845:guards',
                payments: [
                    new CommercePaymentData(
                        method:
                            CommercePaymentMethod::BankTransfer,
                        amountMinor: 2000,
                        reference:
                            'TR-P845-GUARDS',
                        financialAccountId:
                            $bank->id
                    ),
                ]
            );

        $this->assertDomainFailure(
            fn () => $manager->execute(
                $context['selection'],
                $valid,
                $context['admin']
            )
        );

        $execution =
            $manager->execute(
                $context['selection'],
                $valid,
                $context['operator']
            );

        $this->assertDomainFailure(
            fn () => $manager->execute(
                $context['selection'],
                $this->executionData(
                    $context,
                    'p845:guards:second',
                    payments: [
                        new CommercePaymentData(
                            method:
                                CommercePaymentMethod::BankTransfer,
                            amountMinor: 2000,
                            reference:
                                'TR-P845-OTHER',
                            financialAccountId:
                                $bank->id
                        ),
                    ]
                ),
                $context['operator']
            )
        );

        $this->assertSame(
            1,
            CommercePostSaleExchangeExecution::query()
                ->whereKey($execution->id)
                ->count()
        );

        $other =
            $this->context(
                'account-credit',
                replacementPriceMinor: 9000
            );

        $this->assertDomainFailure(
            fn () => $manager->execute(
                $other['selection'],
                $this->executionData(
                    $other,
                    'p845:account-credit',
                    payments: [
                        new CommercePaymentData(
                            method:
                                CommercePaymentMethod::AccountCredit,
                            amountMinor: 2000,
                            reference:
                                'CREDITO',
                            financialAccountId:
                                $bank->id
                        ),
                    ]
                ),
                $other['operator']
            )
        );
    }

    public function test_insufficient_replacement_stock_rolls_back_execution_atomically(): void
    {
        $context =
            $this->context(
                'no-stock',
                replacementPriceMinor: 7000,
                stockReplacement: false
            );

        $inventoryBefore =
            DB::table('inventory_movements')
                ->count();

        $this->assertDomainFailure(
            fn () => app(
                CommercePostSaleExchangeExecutionManager::class
            )->execute(
                $context['selection'],
                $this->executionData(
                    $context,
                    'p845:no-stock'
                ),
                $context['operator']
            )
        );

        $this->assertDatabaseCount(
            'commerce_post_sale_exchange_executions',
            0
        );

        $this->assertSame(
            $inventoryBefore,
            DB::table('inventory_movements')
                ->count()
        );

        $this->assertDatabaseCount(
            'commerce_post_sale_exchange_payments',
            0
        );

        $this->assertDatabaseCount(
            'commerce_post_sale_exchange_credit_grants',
            0
        );
    }

    public function test_execution_children_and_credit_are_immutable(): void
    {
        $context =
            $this->context(
                'immutability',
                replacementPriceMinor: 5000
            );

        $execution = app(
            CommercePostSaleExchangeExecutionManager::class
        )->execute(
            $context['selection'],
            $this->executionData(
                $context,
                'p845:immutability'
            ),
            $context['operator']
        );

        $line = $execution->lines->sole();
        $credit = $execution->creditGrant;

        $this->assertQueryRejected(
            fn () => DB::table(
                'commerce_post_sale_exchange_executions'
            )
                ->where('id', $execution->id)
                ->update([
                    'difference_amount_minor' => 0,
                ])
        );

        $this->assertQueryRejected(
            fn () => DB::table(
                'commerce_post_sale_exchange_execution_lines'
            )
                ->where('id', $line->id)
                ->delete()
        );

        $this->assertQueryRejected(
            fn () => DB::table(
                'commerce_post_sale_exchange_credit_grants'
            )
                ->where('id', $credit->id)
                ->update([
                    'amount_minor' => 1,
                ])
        );

        $this->assertDomainFailure(
            function () use ($execution): void {
                $model =
                    CommercePostSaleExchangeExecution::query()
                        ->findOrFail(
                            $execution->id
                        );

                $model->notes = 'mutación';
                $model->save();
            }
        );

        $this->assertDomainFailure(
            function () use ($line): void {
                $model =
                    CommercePostSaleExchangeExecutionLine::query()
                        ->findOrFail(
                            $line->id
                        );

                $model->condition =
                    InventoryCondition::Used;
                $model->save();
            }
        );
    }

    private function context(
        string $suffix,
        int $replacementPriceMinor,
        bool $stockReplacement = true
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
                        'Cliente P8.4.5 '
                        .$suffix,
                ]);

        $original =
            $this->product(
                'P845-O-'.$suffix,
                'Original P8.4.5 '.$suffix
            );

        $replacement =
            $this->product(
                'P845-R-'.$suffix,
                'Reemplazo P8.4.5 '.$suffix
            );

        app(
            OrganizationProductPriceManager::class
        )->set(
            $original,
            'ARS',
            10000,
            'Precio original P8.4.5.',
            $admin
        );

        app(
            OrganizationProductPriceManager::class
        )->set(
            $replacement,
            'ARS',
            $replacementPriceMinor,
            'Precio reemplazo P8.4.5.',
            $admin
        );

        $stockLines = [
            new InventoryMovementLineData(
                catalogProductId:
                    $original->id,
                condition:
                    InventoryCondition::New,
                enteredQuantity: '1',
                enteredUnitCode:
                    $original
                        ->base_unit_code,
                destinationLocationId:
                    $location->id
            ),
        ];

        if ($stockReplacement) {
            $stockLines[] =
                new InventoryMovementLineData(
                    catalogProductId:
                        $replacement->id,
                    condition:
                        InventoryCondition::New,
                    enteredQuantity: '1',
                    enteredUnitCode:
                        $replacement
                            ->base_unit_code,
                    destinationLocationId:
                        $location->id
                );
        }

        $stock = app(
            InventoryMovementCreator::class
        )->create(
            new InventoryMovementDraftData(
                type:
                    InventoryMovementType::Receipt,
                effectiveAt:
                    CarbonImmutable::now(),
                reason:
                    'Stock previo P8.4.5.',
                idempotencyKey:
                    'p845:stock:'
                    .$suffix.':'
                    .$admin->id,
                lines:
                    $stockLines
            ),
            $admin
        );

        app(
            InventoryMovementConfirmer::class
        )->confirm(
            $stock,
            $admin
        );

        $bank = app(
            FinancialAccountManager::class
        )->create(
            'Banco venta P8.4.5 '
            .$suffix,
            FinancialAccountType::BankAccount,
            'ARS',
            $admin
        );

        $sale = app(
            CommerceCheckoutManager::class
        )->checkout(
            new CommerceCheckoutData(
                currencyCode: 'ARS',
                idempotencyKey:
                    'p845:sale:'
                    .$suffix.':'
                    .$admin->id,
                payments: [
                    new CommercePaymentData(
                        method:
                            CommercePaymentMethod::BankTransfer,
                        amountMinor: 10000,
                        reference:
                            'P845-SALE-'
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
                        quantity: '1',
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

        $request = app(
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
                            $saleLine->id,
                        quantity: '1'
                    ),
                ],
                reason:
                    'El cliente solicita cambio de la unidad original.',
                idempotencyKey:
                    'p845:request:'
                    .$suffix.':'
                    .$admin->id
            ),
            $admin
        );

        $requestLine =
            $request->lines->sole();

        $receipt = app(
            CommercePostSaleReceiptManager::class
        )->receive(
            new CommercePostSaleReceiptData(
                commercePostSaleRequestId:
                    $request->id,
                lines: [
                    new CommercePostSaleReceiptLineData(
                        commercePostSaleRequestLineId:
                            $requestLine->id,
                        quantity: '1',
                        condition:
                            InventoryCondition::Used,
                        destinationLocationId:
                            $location->id
                    ),
                ],
                idempotencyKey:
                    'p845:receipt:'
                    .$suffix.':'
                    .$admin->id
            ),
            $admin
        );

        $receiptLine =
            $receipt->lines->sole();

        $resolution = app(
            CommercePostSaleResolutionManager::class
        )->resolve(
            new CommercePostSaleResolutionData(
                commercePostSaleRequestId:
                    $request->id,
                outcome:
                    CommercePostSaleResolutionOutcome::Exchange,
                lines: [
                    new CommercePostSaleResolutionLineData(
                        commercePostSaleReceiptLineId:
                            $receiptLine->id,
                        quantity: '1',
                        recognizedAmountMinor:
                            7000,
                        adjustmentReason:
                            'La unidad regresó usada y se reconoce un valor comercial menor.'
                    ),
                ],
                reason:
                    'Se autoriza el cambio por la unidad físicamente recibida.',
                idempotencyKey:
                    'p845:resolution:'
                    .$suffix.':'
                    .$admin->id
            ),
            $admin
        );

        $selection = app(
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
                    'p845:selection:'
                    .$suffix.':'
                    .$admin->id
            ),
            $admin
        )->load('lines');

        app(CurrentOrganization::class)
            ->forget($operator);

        return [
            'organization' =>
                $organization,
            'admin' => $admin,
            'operator' => $operator,
            'party' => $party,
            'location' => $location,
            'replacement' =>
                $replacement,
            'selection' =>
                $selection,
        ];
    }

    private function executionData(
        array $context,
        string $idempotencyKey,
        array $payments = []
    ): CommercePostSaleExchangeExecutionData {
        $selectionLine =
            $context['selection']
                ->lines
                ->sole();

        return new CommercePostSaleExchangeExecutionData(
            lines: [
                new CommercePostSaleExchangeExecutionLineData(
                    commercePostSaleExchangeSelectionLineId:
                        $selectionLine->id,
                    sourceLocationId:
                        $context['location']->id,
                    condition:
                        InventoryCondition::New
                ),
            ],
            payments: $payments,
            idempotencyKey:
                $idempotencyKey
        );
    }

    private function product(
        string $skuPrefix,
        string $name
    ): CatalogProduct {
        $category =
            ProductCategory::withoutEvents(
                fn () =>
                    ProductCategory::query()
                        ->firstOrCreate(
                            [
                                'slug' =>
                                    'exchange-execution-tests',
                            ],
                            [
                                'name' =>
                                    'Ejecución cambio posventa',
                                'active' => true,
                            ]
                        )
            );

        return CatalogProduct::withoutEvents(
            fn () =>
                CatalogProduct::query()
                    ->create([
                        'product_category_id' =>
                            $category->id,
                        'sku' =>
                            $skuPrefix.'-'
                            .Str::upper(
                                Str::random(6)
                            ),
                        'name' =>
                            $name,
                        'active' => true,
                    ])
                    ->refresh()
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
                'email_verified_at' => now(),
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

    private function assertDomainFailure(
        callable $callback
    ): void {
        try {
            $callback();

            $this->fail(
                'Se esperaba DomainException.'
            );
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }
    }

    private function assertQueryRejected(
        callable $callback
    ): void {
        try {
            $callback();

            $this->fail(
                'La base de datos aceptó una mutación prohibida.'
            );
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
