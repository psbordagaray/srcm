<?php

namespace Tests\Feature\Commerce;

use App\Domain\Commerce\CommerceCheckoutData;
use App\Domain\Commerce\CommerceCheckoutManager;
use App\Domain\Commerce\CommercePaymentData;
use App\Domain\Commerce\CommercePostSaleCashRefundManager;
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
use App\Domain\Finance\CashLedgerRecorder;
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
use App\Models\CashMovement;
use App\Models\CatalogProduct;
use App\Models\CommercePostSaleCashRefundExecution;
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

class CommercePostSaleCashRefundExecutionFoundationTest
    extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_schema_type_permission_and_trace_are_explicit(): void
    {
        $this->assertTrue(
            Schema::hasColumns(
                'commerce_post_sale_cash_refund_executions',
                [
                    'organization_id',
                    'public_id',
                    'commerce_post_sale_resolution_id',
                    'original_commerce_payment_id',
                    'origin_financial_account_id',
                    'cash_register_session_id',
                    'cash_register_id',
                    'executed_by_user_id',
                    'amount_minor',
                    'currency_code',
                    'idempotency_key',
                    'fingerprint',
                    'executed_at',
                ]
            )
        );

        $this->assertTrue(
            Schema::hasColumn(
                'cash_movements',
                'post_sale_cash_refund_execution_id'
            )
        );

        $this->assertSame(
            'post_sale_refund',
            CashMovementType::PostSaleRefund->value
        );

        $this->assertTrue(
            UserRole::Admin
                ->canExecuteCommercePostSaleCashRefund()
        );

        $this->assertTrue(
            UserRole::Operator
                ->canExecuteCommercePostSaleCashRefund()
        );

        $this->assertFalse(
            UserRole::Viewer
                ->canExecuteCommercePostSaleCashRefund()
        );
    }

    public function test_cash_refund_executes_once_against_original_cash_medium_and_reduces_expected_cash(): void
    {
        $context =
            $this->context(
                'execute',
                splitPayment: false
            );

        $ledger =
            app(
                CashLedgerRecorder::class
            );

        $before =
            $ledger->expectedAmountMinor(
                $context['session'],
                $context['operator']
            );

        $paymentSnapshot =
            DB::table('commerce_payments')
                ->where(
                    'id',
                    $context['cashPayment']->id
                )
                ->first();

        $execution =
            app(
                CommercePostSaleCashRefundManager::class
            )->execute(
                $context['resolution'],
                'REF-EF-001',
                'Reembolso confirmado en mostrador.',
                'p842:execute',
                $context['operator']
            );

        $movement =
            $execution
                ->cashMovement;

        $this->assertNotNull($movement);

        $this->assertSame(
            CashMovementDirection::Out,
            $movement->direction
        );

        $this->assertSame(
            CashMovementType::PostSaleRefund,
            $movement->type
        );

        $this->assertSame(
            $execution->id,
            $movement
                ->post_sale_cash_refund_execution_id
        );

        $this->assertSame(
            $context['cashPayment']->id,
            $execution
                ->original_commerce_payment_id
        );

        $this->assertSame(
            $context['cash']->id,
            $execution
                ->origin_financial_account_id
        );

        $this->assertSame(
            10000,
            $execution->amount_minor
        );

        $this->assertSame(
            $before - 10000,
            $ledger->expectedAmountMinor(
                $context['session'],
                $context['operator']
            )
        );

        $paymentAfter =
            DB::table('commerce_payments')
                ->where(
                    'id',
                    $context['cashPayment']->id
                )
                ->first();

        $this->assertEquals(
            $paymentSnapshot,
            $paymentAfter
        );

        $this->assertDatabaseCount(
            'financial_external_movements',
            0
        );

        $this->assertDatabaseCount(
            'customer_credit_grants',
            0
        );

        $this->assertDatabaseHas(
            'audit_logs',
            [
                'event' =>
                    'commerce_post_sale_cash_refund_executed',
            ]
        );

        $this->assertDatabaseHas(
            'audit_logs',
            [
                'event' =>
                    'post_sale_cash_refund_cash_recorded',
            ]
        );
    }

    public function test_same_cash_refund_execution_is_idempotent_and_second_operation_fails_closed(): void
    {
        $context =
            $this->context(
                'idem',
                splitPayment: false
            );

        $manager =
            app(
                CommercePostSaleCashRefundManager::class
            );

        $first =
            $manager->execute(
                $context['resolution'],
                'REF-IDEM',
                null,
                'p842:idem',
                $context['operator']
            );

        $second =
            $manager->execute(
                $context['resolution'],
                'REF-IDEM',
                null,
                'p842:idem',
                $context['operator']
            );

        $this->assertSame(
            $first->id,
            $second->id
        );

        $this->assertDomainFailure(
            fn () => $manager->execute(
                $context['resolution'],
                'OTRA-REF',
                null,
                'p842:other',
                $context['operator']
            )
        );

        $this->assertDatabaseCount(
            'commerce_post_sale_cash_refund_executions',
            1
        );

        $this->assertSame(
            2,
            DB::table('cash_movements')
                ->where(
                    'cash_register_session_id',
                    $context['session']->id
                )
                ->count()
        );
    }

    public function test_resolver_non_cash_medium_and_operator_without_matching_shift_fail_closed(): void
    {
        $context =
            $this->context(
                'guards',
                splitPayment: true
            );

        $manager =
            app(
                CommercePostSaleCashRefundManager::class
            );

        $this->assertDomainFailure(
            fn () => $manager->execute(
                $context['resolution'],
                null,
                null,
                'p842:resolver',
                $context['admin']
            )
        );

        $otherOperator =
            $this->member(
                $context['organization'],
                UserRole::Operator
            );

        $this->assertDomainFailure(
            fn () => $manager->execute(
                $context['resolution'],
                null,
                null,
                'p842:no-shift',
                $otherOperator
            )
        );

        $bankResolution =
            $this->resolvePart(
                $context,
                $context['receiptLine']->id,
                '1',
                10000,
                $context['bankPayment']->id,
                'p842:bank-resolution'
            );

        $this->assertDomainFailure(
            fn () => $manager->execute(
                $bankResolution,
                null,
                null,
                'p842:bank-execute',
                $context['operator']
            )
        );

        $this->assertDatabaseCount(
            'commerce_post_sale_cash_refund_executions',
            0
        );
    }

    public function test_aggregate_cash_refund_cannot_exceed_original_cash_payment(): void
    {
        $context =
            $this->context(
                'aggregate',
                splitPayment: true,
                resolveInitially: false
            );

        $firstResolution =
            $this->resolvePart(
                $context,
                $context['receiptLine']->id,
                '1',
                10000,
                $context['cashPayment']->id,
                'p842:aggregate:r1'
            );

        $secondResolution =
            $this->resolvePart(
                $context,
                $context['receiptLine']->id,
                '1',
                10000,
                $context['cashPayment']->id,
                'p842:aggregate:r2'
            );

        $manager =
            app(
                CommercePostSaleCashRefundManager::class
            );

        $manager->execute(
            $firstResolution,
            null,
            null,
            'p842:aggregate:e1',
            $context['operator']
        );

        $this->assertDomainFailure(
            fn () => $manager->execute(
                $secondResolution,
                null,
                null,
                'p842:aggregate:e2',
                $context['operator']
            )
        );

        $this->assertDatabaseCount(
            'commerce_post_sale_cash_refund_executions',
            1
        );

        $this->assertSame(
            1,
            CashMovement::query()
                ->where(
                    'type',
                    CashMovementType::PostSaleRefund
                )
                ->count()
        );
    }

    public function test_execution_and_cash_refund_movement_are_append_only_and_database_guarded(): void
    {
        $context =
            $this->context(
                'immutability',
                splitPayment: false
            );

        $execution =
            app(
                CommercePostSaleCashRefundManager::class
            )->execute(
                $context['resolution'],
                null,
                null,
                'p842:immutability',
                $context['operator']
            );

        $this->assertQueryRejected(
            fn () => DB::table(
                'commerce_post_sale_cash_refund_executions'
            )
                ->where(
                    'id',
                    $execution->id
                )
                ->update([
                    'amount_minor' => 1,
                ])
        );

        $this->assertQueryRejected(
            fn () => DB::table(
                'commerce_post_sale_cash_refund_executions'
            )
                ->where(
                    'id',
                    $execution->id
                )
                ->delete()
        );

        $this->assertDomainFailure(
            function () use ($execution): void {
                $model =
                    CommercePostSaleCashRefundExecution::query()
                        ->findOrFail(
                            $execution->id
                        );

                $model->amount_minor = 1;
                $model->save();
            }
        );

        $this->assertQueryRejected(
            fn () => DB::table(
                'cash_movements'
            )->insert([
                'organization_id' =>
                    $context['organization']->id,
                'public_id' =>
                    (string) Str::uuid(),
                'cash_register_session_id' =>
                    $context['session']->id,
                'cash_register_id' =>
                    $context['register']->id,
                'financial_account_id' =>
                    $context['cash']->id,
                'destination_financial_account_id' =>
                    null,
                'cash_security_drop_request_id' =>
                    null,
                'purchase_payment_execution_id' =>
                    null,
                'post_sale_cash_refund_execution_id' =>
                    null,
                'commerce_payment_id' =>
                    null,
                'direction' =>
                    'out',
                'type' =>
                    'post_sale_refund',
                'reason_code' =>
                    null,
                'note' =>
                    null,
                'amount_minor' =>
                    1,
                'currency_code' =>
                    'ARS',
                'idempotency_key' =>
                    'p842:forged',
                'fingerprint' =>
                    str_repeat('a', 64),
                'recorded_by_user_id' =>
                    $context['operator']->id,
                'occurred_at' =>
                    now(),
                'created_at' =>
                    now(),
            ])
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function context(
        string $suffix,
        bool $splitPayment,
        bool $resolveInitially = true
    ): array {
        $organization =
            Organization::query()
                ->where(
                    'slug',
                    'sulu-tv'
                )
                ->firstOrFail();

        $admin =
            $this->member(
                $organization,
                UserRole::Admin
            );

        $operator =
            $this->member(
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
                        'Cliente P8.4.2 '
                        .$suffix,
                ]);

        $category =
            ProductCategory::withoutEvents(
                fn () =>
                    ProductCategory::query()
                        ->firstOrCreate(
                            [
                                'slug' =>
                                    'post-sale-cash-refund-tests',
                            ],
                            [
                                'name' =>
                                    'Reembolso efectivo posventa',
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
                                'P842-'
                                .Str::upper(
                                    Str::random(8)
                                ),
                            'name' =>
                                'Producto P8.4.2 '
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
            10000,
            'Precio P8.4.2.',
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
                        'Stock previo P8.4.2.',
                    idempotencyKey:
                        'p842:stock:'
                        .$suffix.':'
                        .$operator->id,
                    lines: [
                        new InventoryMovementLineData(
                            catalogProductId:
                                $product->id,
                            condition:
                                InventoryCondition::New,
                            enteredQuantity:
                                '2',
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

        $cash =
            app(
                FinancialAccountManager::class
            )->create(
                'Caja P8.4.2 '.$suffix,
                FinancialAccountType::CashBox,
                'ARS',
                $admin
            );

        $register =
            app(
                CashRegisterManager::class
            )->create(
                'Caja P8.4.2 '.$suffix,
                $cash,
                $admin
            );

        $session =
            app(
                CashRegisterSessionManager::class
            )->open(
                $register,
                50000,
                'p842:session:'
                .$suffix.':'
                .$operator->id,
                $operator
            );

        $payments = [
            new CommercePaymentData(
                method:
                    CommercePaymentMethod::Cash,
                amountMinor:
                    $splitPayment
                        ? 10000
                        : 20000,
                financialAccountId:
                    $cash->id,
                tenderedAmountMinor:
                    $splitPayment
                        ? 10000
                        : 20000
            ),
        ];

        $bank = null;

        if ($splitPayment) {
            $bank =
                app(
                    FinancialAccountManager::class
                )->create(
                    'Banco P8.4.2 '.$suffix,
                    FinancialAccountType::BankAccount,
                    'ARS',
                    $admin
                );

            $payments[] =
                new CommercePaymentData(
                    method:
                        CommercePaymentMethod::BankTransfer,
                    amountMinor:
                        10000,
                    reference:
                        'P842-BANK-'
                        .$suffix,
                    financialAccountId:
                        $bank->id
                );
        }

        $sale =
            app(
                CommerceCheckoutManager::class
            )->checkout(
                new CommerceCheckoutData(
                    currencyCode:
                        'ARS',
                    idempotencyKey:
                        'p842:sale:'
                        .$suffix.':'
                        .$operator->id,
                    payments:
                        $payments,
                    productLines: [
                        new CommerceProductLineData(
                            catalogProductId:
                                $product->id,
                            sourceLocationId:
                                $location->id,
                            condition:
                                InventoryCondition::New,
                            quantity:
                                '2',
                            unitPriceMinor:
                                10000
                        ),
                    ],
                    customerBusinessPartyId:
                        $party->id
                ),
                $operator
            )->load(
                'lines.product',
                'payments'
            );

        $cashPayment =
            $sale->payments
                ->first(
                    fn ($payment) =>
                        $payment->method
                            === CommercePaymentMethod::Cash
                );

        $bankPayment =
            $sale->payments
                ->first(
                    fn ($payment) =>
                        $payment->method
                            === CommercePaymentMethod::BankTransfer
                );

        $request =
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
                                $sale
                                    ->lines
                                    ->sole()
                                    ->id,
                            quantity:
                                '2'
                        ),
                    ],
                    reason:
                        'El cliente solicita devolución con eventual reembolso en efectivo.',
                    idempotencyKey:
                        'p842:request:'
                        .$suffix.':'
                        .$operator->id
                ),
                $operator
            );

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
                            quantity:
                                '2',
                            condition:
                                InventoryCondition::Used,
                            destinationLocationId:
                                $location->id
                        ),
                    ],
                    idempotencyKey:
                        'p842:receipt:'
                        .$suffix.':'
                        .$operator->id
                ),
                $operator
            );

        $context = [
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
            'product' =>
                $product,
            'cash' =>
                $cash,
            'bank' =>
                $bank,
            'register' =>
                $register,
            'session' =>
                $session,
            'sale' =>
                $sale,
            'cashPayment' =>
                $cashPayment,
            'bankPayment' =>
                $bankPayment,
            'request' =>
                $request,
            'receiptLine' =>
                $receipt->lines->sole(),
        ];

        if ($resolveInitially) {
            $context['resolution'] =
                $this->resolvePart(
                    $context,
                    $context['receiptLine']->id,
                    '1',
                    10000,
                    $cashPayment->id,
                    'p842:resolution:'
                    .$suffix
                );
        }

        return $context;
    }

    private function resolvePart(
        array $context,
        int $receiptLineId,
        string $quantity,
        int $recognizedAmountMinor,
        int $preferredPaymentId,
        string $idempotencyKey
    ): \App\Models\CommercePostSaleResolution {
        return app(
            CommercePostSaleResolutionManager::class
        )->resolve(
            new CommercePostSaleResolutionData(
                commercePostSaleRequestId:
                    $context['request']->id,
                outcome:
                    CommercePostSaleResolutionOutcome::Refund,
                lines: [
                    new CommercePostSaleResolutionLineData(
                        commercePostSaleReceiptLineId:
                            $receiptLineId,
                        quantity:
                            $quantity,
                        recognizedAmountMinor:
                            $recognizedAmountMinor
                    ),
                ],
                reason:
                    'Se autoriza el valor recibido para ejecutar el reembolso correspondiente.',
                idempotencyKey:
                    $idempotencyKey,
                preferredOriginalPaymentId:
                    $preferredPaymentId
            ),
            $context['admin']
        );
    }

    private function member(
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

        app(
            CurrentOrganization::class
        )->forget($user);

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
