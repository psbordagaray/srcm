<?php

namespace Tests\Feature\Commerce;

use App\Domain\Commerce\CommerceCheckoutData;
use App\Domain\Commerce\CommerceCheckoutManager;
use App\Domain\Commerce\CommercePaymentData;
use App\Domain\Commerce\CommercePostSaleExternalRefundInstructionManager;
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
use App\Domain\Finance\FinancialProviderCompatibilityRegistry;
use App\Domain\Finance\FinancialProviderConnectionCompatibilityManager;
use App\Domain\Finance\FinancialProviderConnectionHealthManager;
use App\Domain\Finance\FinancialProviderConnectionManager;
use App\Domain\Finance\FinancialProviderHealthObservation;
use App\Domain\Inventory\InventoryMovementConfirmer;
use App\Domain\Inventory\InventoryMovementCreator;
use App\Domain\Inventory\InventoryMovementDraftData;
use App\Domain\Inventory\InventoryMovementLineData;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CommercePaymentMethod;
use App\Enums\CommercePostSaleIntent;
use App\Enums\CommercePostSaleResolutionOutcome;
use App\Enums\FinancialAccountType;
use App\Enums\FinancialProviderCapability;
use App\Enums\FinancialProviderCompatibilityStatus;
use App\Enums\FinancialProviderConnectionHealthStatus;
use App\Enums\InventoryCondition;
use App\Enums\InventoryMovementType;
use App\Enums\UserRole;
use App\Models\BusinessParty;
use App\Models\CatalogProduct;
use App\Models\CommercePostSaleExternalRefundInstruction;
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

class CommercePostSaleExternalRefundInstructionFoundationTest
    extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_schema_and_permissions_are_explicit(): void
    {
        $this->assertTrue(
            Schema::hasColumns(
                'commerce_post_sale_external_refund_instructions',
                [
                    'organization_id',
                    'public_id',
                    'commerce_post_sale_resolution_id',
                    'original_commerce_payment_id',
                    'financial_account_id',
                    'financial_provider_connection_id',
                    'requested_by_user_id',
                    'amount_minor',
                    'currency_code',
                    'idempotency_key',
                    'fingerprint',
                    'requested_at',
                ]
            )
        );

        $this->assertTrue(
            UserRole::Admin
                ->canExecuteCommercePostSaleExternalRefund()
        );

        $this->assertTrue(
            UserRole::Operator
                ->canExecuteCommercePostSaleExternalRefund()
        );

        $this->assertFalse(
            UserRole::Viewer
                ->canExecuteCommercePostSaleExternalRefund()
        );
    }

    public function test_healthy_compatible_provider_creates_instruction_without_money_effect(): void
    {
        $context =
            $this->context(
                'compatible'
            );

        $paymentBefore =
            DB::table('commerce_payments')
                ->where(
                    'id',
                    $context['externalPayment']->id
                )
                ->first();

        $cashBefore =
            DB::table('cash_movements')
                ->count();

        $inventoryBefore =
            DB::table('inventory_movements')
                ->count();

        $instruction =
            app(
                CommercePostSaleExternalRefundInstructionManager::class
            )->request(
                $context['resolution'],
                'p843:compatible',
                $context['operator']
            );

        $this->assertSame(
            $context['resolution']->id,
            $instruction
                ->commerce_post_sale_resolution_id
        );

        $this->assertSame(
            $context['externalPayment']->id,
            $instruction
                ->original_commerce_payment_id
        );

        $this->assertSame(
            $context['externalAccount']->id,
            $instruction
                ->financial_account_id
        );

        $this->assertSame(
            $context['connection']->id,
            $instruction
                ->financial_provider_connection_id
        );

        $this->assertSame(
            $context['operator']->id,
            $instruction
                ->requested_by_user_id
        );

        $this->assertSame(
            10000,
            $instruction->amount_minor
        );

        $this->assertSame(
            'ARS',
            $instruction->currency_code
        );

        $this->assertSame(
            $instruction->id,
            $context['resolution']
                ->refresh()
                ->externalRefundInstruction
                ->id
        );

        $this->assertSame(
            $instruction->id,
            $context['externalPayment']
                ->refresh()
                ->externalRefundInstructions()
                ->sole()
                ->id
        );

        $this->assertSame(
            $instruction->id,
            $context['connection']
                ->refresh()
                ->externalRefundInstructions()
                ->sole()
                ->id
        );

        $this->assertEquals(
            $paymentBefore,
            DB::table('commerce_payments')
                ->where(
                    'id',
                    $context['externalPayment']->id
                )
                ->first()
        );

        $this->assertSame(
            $cashBefore,
            DB::table('cash_movements')
                ->count()
        );

        $this->assertSame(
            $inventoryBefore,
            DB::table('inventory_movements')
                ->count()
        );

        $this->assertDatabaseCount(
            'financial_external_movements',
            0
        );

        $this->assertDatabaseCount(
            'commerce_post_sale_cash_refund_executions',
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
                    'commerce_post_sale_external_refund_instructed',
            ]
        );
    }

    public function test_current_mercado_pago_refund_unknown_contract_blocks_instruction(): void
    {
        $context =
            $this->context(
                'mp-unknown',
                providerKey:
                    'mercado-pago',
                healthyRefund:
                    false
            );

        $this->assertDomainFailure(
            fn () => app(
                CommercePostSaleExternalRefundInstructionManager::class
            )->request(
                $context['resolution'],
                'p843:mp-unknown',
                $context['operator']
            )
        );

        $this->assertQueryRejected(
            fn () => DB::table(
                'commerce_post_sale_external_refund_instructions'
            )->insert([
                'organization_id' =>
                    $context['organization']->id,
                'public_id' =>
                    (string) Str::uuid(),
                'commerce_post_sale_resolution_id' =>
                    $context['resolution']->id,
                'original_commerce_payment_id' =>
                    $context['externalPayment']->id,
                'financial_account_id' =>
                    $context['externalAccount']->id,
                'financial_provider_connection_id' =>
                    $context['connection']->id,
                'requested_by_user_id' =>
                    $context['operator']->id,
                'amount_minor' =>
                    10000,
                'currency_code' =>
                    'ARS',
                'idempotency_key' =>
                    'p843:mp-db-bypass',
                'fingerprint' =>
                    str_repeat('a', 64),
                'requested_at' =>
                    now(),
                'created_at' =>
                    now(),
            ])
        );

        $this->assertDatabaseCount(
            'commerce_post_sale_external_refund_instructions',
            0
        );

        $this->assertDatabaseCount(
            'financial_external_movements',
            0
        );
    }

    public function test_resolver_and_payment_without_external_operation_fail_closed(): void
    {
        $context =
            $this->context(
                'segregation'
            );

        $manager =
            app(
                CommercePostSaleExternalRefundInstructionManager::class
            );

        $this->assertDomainFailure(
            fn () => $manager->request(
                $context['resolution'],
                'p843:same-resolver',
                $context['admin']
            )
        );

        $withoutExternalId =
            $this->context(
                'missing-operation',
                externalOperationId:
                    null
            );

        $this->assertDomainFailure(
            fn () => $manager->request(
                $withoutExternalId[
                    'resolution'
                ],
                'p843:missing-operation',
                $withoutExternalId[
                    'operator'
                ]
            )
        );

        $this->assertDatabaseCount(
            'commerce_post_sale_external_refund_instructions',
            0
        );
    }

    public function test_aggregate_instructions_cannot_exceed_original_payment(): void
    {
        $context =
            $this->context(
                'aggregate',
                splitPayment:
                    true,
                resolveInitially:
                    false
            );

        $firstResolution =
            $this->resolvePart(
                $context,
                '1',
                10000,
                'p843:aggregate:r1'
            );

        $secondResolution =
            $this->resolvePart(
                $context,
                '1',
                10000,
                'p843:aggregate:r2'
            );

        $manager =
            app(
                CommercePostSaleExternalRefundInstructionManager::class
            );

        $manager->request(
            $firstResolution,
            'p843:aggregate:i1',
            $context['operator']
        );

        $this->assertDomainFailure(
            fn () => $manager->request(
                $secondResolution,
                'p843:aggregate:i2',
                $context['operator']
            )
        );

        $this->assertDatabaseCount(
            'commerce_post_sale_external_refund_instructions',
            1
        );
    }

    public function test_instruction_is_idempotent_append_only_and_database_guarded(): void
    {
        $context =
            $this->context(
                'immutability'
            );

        $manager =
            app(
                CommercePostSaleExternalRefundInstructionManager::class
            );

        $first =
            $manager->request(
                $context['resolution'],
                'p843:idem',
                $context['operator']
            );

        $second =
            $manager->request(
                $context['resolution'],
                'p843:idem',
                $context['operator']
            );

        $this->assertSame(
            $first->id,
            $second->id
        );

        $this->assertDomainFailure(
            fn () => $manager->request(
                $context['resolution'],
                'p843:other-key',
                $context['operator']
            )
        );

        $this->assertQueryRejected(
            fn () => DB::table(
                'commerce_post_sale_external_refund_instructions'
            )
                ->where('id', $first->id)
                ->update([
                    'amount_minor' => 1,
                ])
        );

        $this->assertQueryRejected(
            fn () => DB::table(
                'commerce_post_sale_external_refund_instructions'
            )
                ->where('id', $first->id)
                ->delete()
        );

        $this->assertDomainFailure(
            function () use ($first): void {
                $model =
                    CommercePostSaleExternalRefundInstruction::query()
                        ->findOrFail(
                            $first->id
                        );

                $model->amount_minor = 1;
                $model->save();
            }
        );

        $this->assertDatabaseCount(
            'commerce_post_sale_external_refund_instructions',
            1
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function context(
        string $suffix,
        string $providerKey = 'test-refund',
        bool $healthyRefund = true,
        bool $splitPayment = false,
        bool $resolveInitially = true,
        ?string $externalOperationId = 'AUTO'
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
                        'Cliente P8.4.3 '
                        .$suffix,
                ]);

        $category =
            ProductCategory::withoutEvents(
                fn () =>
                    ProductCategory::query()
                        ->firstOrCreate(
                            [
                                'slug' =>
                                    'post-sale-external-refund-tests',
                            ],
                            [
                                'name' =>
                                    'Reembolso externo posventa',
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
                                'P843-'
                                .Str::upper(
                                    Str::random(8)
                                ),
                            'name' =>
                                'Producto P8.4.3 '
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
            'Precio P8.4.3.',
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
                        'Stock previo P8.4.3.',
                    idempotencyKey:
                        'p843:stock:'
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

        $providerLabel =
            $providerKey === 'mercado-pago'
                ? 'Mercado Pago'
                : 'Test Refund';

        $externalAccount =
            app(
                FinancialAccountManager::class
            )->create(
                'Externa P8.4.3 '
                .$suffix,
                FinancialAccountType::DigitalWallet,
                'ARS',
                $admin,
                $providerLabel,
                'P8.4.3 test'
            );

        $connection =
            app(
                FinancialProviderConnectionManager::class
            )->connect(
                $externalAccount,
                $providerKey,
                $admin,
                'acct-'.$suffix
            );

        $compatibility =
            $this->compatibility(
                $providerKey,
                $suffix
            );

        app(
            FinancialProviderConnectionCompatibilityManager::class
        )->bind(
            $connection,
            $compatibility,
            $admin
        );

        if ($healthyRefund) {
            app(
                FinancialProviderConnectionHealthManager::class
            )->record(
                $connection,
                new FinancialProviderHealthObservation(
                    capability:
                        FinancialProviderCapability::Refund,
                    status:
                        FinancialProviderConnectionHealthStatus::Healthy,
                    checkedAt:
                        CarbonImmutable::now(),
                    sourceKey:
                        'test:refund:healthy:'
                        .Str::lower(
                            Str::random(6)
                        )
                )
            );
        }

        $payments = [
            new CommercePaymentData(
                method:
                    CommercePaymentMethod::DigitalWallet,
                amountMinor:
                    $splitPayment
                        ? 10000
                        : 20000,
                reference:
                    'P843-'.$suffix,
                processor:
                    $providerLabel,
                externalOperationId:
                    $externalOperationId === null
                        ? null
                        : (
                            $externalOperationId === 'AUTO'
                                ? 'EXT-P843-'
                                    .$suffix
                                : $externalOperationId
                        ),
                providerStatus:
                    'approved',
                financialAccountId:
                    $externalAccount->id
            ),
        ];

        $bankAccount = null;

        if ($splitPayment) {
            $bankAccount =
                app(
                    FinancialAccountManager::class
                )->create(
                    'Banco P8.4.3 '
                    .$suffix,
                    FinancialAccountType::BankAccount,
                    'ARS',
                    $admin,
                    'Banco'
                );

            $payments[] =
                new CommercePaymentData(
                    method:
                        CommercePaymentMethod::BankTransfer,
                    amountMinor:
                        10000,
                    reference:
                        'P843-BANK-'
                        .$suffix,
                    financialAccountId:
                        $bankAccount->id
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
                        'p843:sale:'
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

        $externalPayment =
            $sale->payments
                ->first(
                    fn ($payment) =>
                        $payment->financial_account_id
                            === $externalAccount->id
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
                        'El cliente solicita devolución con eventual reembolso al medio externo original.',
                    idempotencyKey:
                        'p843:request:'
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
                        'p843:receipt:'
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
            'externalAccount' =>
                $externalAccount,
            'bankAccount' =>
                $bankAccount,
            'connection' =>
                $connection,
            'compatibility' =>
                $compatibility,
            'sale' =>
                $sale,
            'externalPayment' =>
                $externalPayment,
            'request' =>
                $request,
            'receiptLine' =>
                $receipt->lines->sole(),
        ];

        if ($resolveInitially) {
            $context['resolution'] =
                $this->resolvePart(
                    $context,
                    '1',
                    10000,
                    'p843:resolution:'
                    .$suffix
                );
        }

        return $context;
    }

    private function compatibility(
        string $providerKey,
        string $suffix
    ) {
        $registry =
            app(
                FinancialProviderCompatibilityRegistry::class
            );

        if ($providerKey === 'mercado-pago') {
            [$mercadoPago] =
                $registry
                    ->seedReferenceRegistry();

            return $mercadoPago;
        }

        return $registry->register(
            registryKey:
                'test-refund:refund-v1:'
                .Str::lower(
                    Str::random(8)
                ),
            providerKey:
                'test-refund',
            providerLabel:
                'Test Refund',
            providerContractVersion:
                'refund-v1',
            providerContractReference:
                'Contrato sintético exclusivo de tests P8.4.3.',
            adapterClass:
                null,
            adapterContractVersion:
                'refund-instruction-v1',
            status:
                FinancialProviderCompatibilityStatus::Compatible,
            migrationRequired:
                false,
            srcmVersion:
                '47fb3687dcf17049ec1aa15198463dc389f0af02',
            verifiedAt:
                CarbonImmutable::now(),
            capabilities: [
                [
                    'capability' =>
                        FinancialProviderCapability::Refund,
                    'status' =>
                        FinancialProviderCompatibilityStatus::Compatible,
                    'required' =>
                        true,
                    'evidence_reference' =>
                        'P8.4.3 synthetic compatible refund contract.',
                    'notes' =>
                        'No HTTP real.',
                ],
            ],
            notes:
                'Fixture local de tests; no proveedor productivo.'
        );
    }

    private function resolvePart(
        array $context,
        string $quantity,
        int $recognizedAmountMinor,
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
                            $context['receiptLine']->id,
                        quantity:
                            $quantity,
                        recognizedAmountMinor:
                            $recognizedAmountMinor
                    ),
                ],
                reason:
                    'Se autoriza reembolso al medio externo original por la mercadería recibida.',
                idempotencyKey:
                    $idempotencyKey,
                preferredOriginalPaymentId:
                    $context[
                        'externalPayment'
                    ]->id
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
