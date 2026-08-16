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
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class CommercePostSaleResolutionFoundationTest
    extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_schema_and_admin_resolution_authority_are_explicit(): void
    {
        $this->assertTrue(
            Schema::hasColumns(
                'commerce_post_sale_resolutions',
                [
                    'organization_id',
                    'public_id',
                    'commerce_post_sale_request_id',
                    'outcome',
                    'currency_code',
                    'preferred_original_payment_id',
                    'reason',
                    'resolved_by_user_id',
                    'resolved_at',
                    'idempotency_key',
                    'fingerprint',
                ]
            )
        );

        $this->assertTrue(
            Schema::hasColumns(
                'commerce_post_sale_resolution_lines',
                [
                    'organization_id',
                    'commerce_post_sale_resolution_id',
                    'commerce_post_sale_receipt_line_id',
                    'quantity',
                    'baseline_amount_minor',
                    'recognized_amount_minor',
                    'adjustment_reason',
                ]
            )
        );

        $this->assertTrue(
            UserRole::Admin
                ->canResolveCommercePostSale()
        );

        $this->assertFalse(
            UserRole::Operator
                ->canResolveCommercePostSale()
        );

        $this->assertFalse(
            UserRole::Viewer
                ->canResolveCommercePostSale()
        );
    }

    public function test_refund_instruction_values_received_goods_without_moving_money(): void
    {
        [
            $request,
            $receiptLine,
            $actor,
            $sale,
        ] = $this->receivedReturn(
            'refund'
        );

        $payment =
            $sale->payments->sole();

        $cashBefore =
            DB::table('cash_movements')
                ->count();

        $inventoryBefore =
            DB::table(
                'inventory_movements'
            )->count();

        $resolution = app(
            CommercePostSaleResolutionManager::class
        )->resolve(
            new CommercePostSaleResolutionData(
                commercePostSaleRequestId:
                    $request->id,
                outcome:
                    CommercePostSaleResolutionOutcome::Refund,
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
                    'Se reconoce una unidad recibida al valor original de venta.',
                idempotencyKey:
                    'p83:refund',
                preferredOriginalPaymentId:
                    $payment->id
            ),
            $actor
        );

        $line =
            $resolution->lines->sole();

        $this->assertSame(
            CommercePostSaleResolutionOutcome::Refund,
            $resolution->outcome
        );

        $this->assertSame(
            'ARS',
            $resolution->currency_code
        );

        $this->assertSame(
            $payment->id,
            $resolution
                ->preferred_original_payment_id
        );

        $this->assertSame(
            10000,
            $line->baseline_amount_minor
        );

        $this->assertSame(
            10000,
            $line->recognized_amount_minor
        );

        $this->assertSame(
            10000,
            $resolution
                ->recognizedAmountMinor()
        );

        $this->assertSame(
            $cashBefore,
            DB::table('cash_movements')
                ->count()
        );

        $this->assertSame(
            $inventoryBefore,
            DB::table(
                'inventory_movements'
            )->count()
        );

        $this->assertDatabaseCount(
            'financial_external_movements',
            0
        );

        $this->assertDatabaseCount(
            'payment_reconciliations',
            0
        );

        $this->assertSame(
            1,
            DB::table('commerce_payments')
                ->where(
                    'commerce_sale_id',
                    $sale->id
                )
                ->count()
        );

        $this->assertDatabaseHas(
            'audit_logs',
            [
                'event' =>
                    'commerce_post_sale_resolution_recorded',
            ]
        );
    }

    public function test_reduced_value_requires_explicit_adjustment_reason(): void
    {
        [
            $request,
            $receiptLine,
            $actor,
        ] = $this->receivedReturn(
            'adjustment'
        );

        $manager = app(
            CommercePostSaleResolutionManager::class
        );

        $this->assertDomainFailure(
            fn () => $manager->resolve(
                new CommercePostSaleResolutionData(
                    commercePostSaleRequestId:
                        $request->id,
                    outcome:
                        CommercePostSaleResolutionOutcome::CustomerCredit,
                    lines: [
                        new CommercePostSaleResolutionLineData(
                            commercePostSaleReceiptLineId:
                                $receiptLine->id,
                            quantity: '1',
                            recognizedAmountMinor:
                                7000
                        ),
                    ],
                    reason:
                        'Se propone reconocer un valor menor por condición física.',
                    idempotencyKey:
                        'p83:adjustment:missing'
                ),
                $actor
            )
        );

        $resolution =
            $manager->resolve(
                new CommercePostSaleResolutionData(
                    commercePostSaleRequestId:
                        $request->id,
                    outcome:
                        CommercePostSaleResolutionOutcome::CustomerCredit,
                    lines: [
                        new CommercePostSaleResolutionLineData(
                            commercePostSaleReceiptLineId:
                                $receiptLine->id,
                            quantity: '1',
                            recognizedAmountMinor:
                                7000,
                            adjustmentReason:
                                'El artículo volvió usado y con desgaste visible verificado.'
                        ),
                    ],
                    reason:
                        'Se reconoce un saldo a favor reducido por la condición constatada.',
                    idempotencyKey:
                        'p83:adjustment:ok'
                ),
                $actor
            );

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

    public function test_cumulative_resolved_quantity_cannot_exceed_physical_receipt(): void
    {
        [
            $request,
            $receiptLine,
            $actor,
        ] = $this->receivedReturn(
            'cumulative'
        );

        $manager = app(
            CommercePostSaleResolutionManager::class
        );

        foreach ([1, 2] as $part) {
            $manager->resolve(
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
                                10000
                        ),
                    ],
                    reason:
                        'La unidad recibida queda valuada para continuar un cambio comercial.',
                    idempotencyKey:
                        'p83:cumulative:'.$part
                ),
                $actor
            );
        }

        $this->assertDomainFailure(
            fn () => $manager->resolve(
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
                                10000
                        ),
                    ],
                    reason:
                        'Una tercera unidad no fue físicamente recibida y debe rechazarse.',
                    idempotencyKey:
                        'p83:cumulative:3'
                ),
                $actor
            )
        );

        $this->assertDatabaseCount(
            'commerce_post_sale_resolutions',
            2
        );
    }

    public function test_customer_credit_requires_identified_customer_and_original_payment_must_match_sale(): void
    {
        [
            $request,
            $receiptLine,
            $actor,
        ] = $this->receivedReturn(
            'anonymous',
            withCustomer: false
        );

        $this->assertDomainFailure(
            fn () => app(
                CommercePostSaleResolutionManager::class
            )->resolve(
                new CommercePostSaleResolutionData(
                    commercePostSaleRequestId:
                        $request->id,
                    outcome:
                        CommercePostSaleResolutionOutcome::CustomerCredit,
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
                        'No se puede crear saldo a favor sin cliente identificado.',
                    idempotencyKey:
                        'p83:anonymous-credit'
                ),
                $actor
            )
        );

        [
            $otherRequest,
            $otherReceiptLine,
            ,
            $otherSale,
        ] = $this->receivedReturn(
            'other-sale'
        );

        $foreignPayment =
            $otherSale->payments->sole();

        $this->assertDomainFailure(
            fn () => app(
                CommercePostSaleResolutionManager::class
            )->resolve(
                new CommercePostSaleResolutionData(
                    commercePostSaleRequestId:
                        $request->id,
                    outcome:
                        CommercePostSaleResolutionOutcome::Refund,
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
                        'Un reembolso no puede apuntar a un pago de otra venta.',
                    idempotencyKey:
                        'p83:foreign-payment',
                    preferredOriginalPaymentId:
                        $foreignPayment->id
                ),
                $actor
            )
        );

        $this->assertNotSame(
            $request->id,
            $otherRequest->id
        );

        $this->assertNotSame(
            $receiptLine->id,
            $otherReceiptLine->id
        );

        $this->assertDatabaseCount(
            'commerce_post_sale_resolutions',
            0
        );
    }

    public function test_resolution_is_idempotent_append_only_and_operator_fails_closed(): void
    {
        [
            $request,
            $receiptLine,
            $actor,
            ,
            $organization,
        ] = $this->receivedReturn(
            'immutability'
        );

        $data =
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
                            10000
                    ),
                ],
                reason:
                    'La unidad recibida se reconoce para continuar un cambio.',
                idempotencyKey:
                    'p83:idem'
            );

        $manager = app(
            CommercePostSaleResolutionManager::class
        );

        $first =
            $manager->resolve(
                $data,
                $actor
            );

        $second =
            $manager->resolve(
                $data,
                $actor
            );

        $this->assertSame(
            $first->id,
            $second->id
        );

        $this->assertQueryRejected(
            fn () => DB::table(
                'commerce_post_sale_resolutions'
            )
                ->where(
                    'id',
                    $first->id
                )
                ->update([
                    'reason' =>
                        'Manipulación posterior',
                ])
        );

        $this->assertQueryRejected(
            fn () => DB::table(
                'commerce_post_sale_resolution_lines'
            )
                ->where(
                    'commerce_post_sale_resolution_id',
                    $first->id
                )
                ->update([
                    'recognized_amount_minor' =>
                        5000,
                ])
        );

        $operator = $this->user(
            $organization,
            UserRole::Operator
        );

        $this->assertDomainFailure(
            fn () => $manager->resolve(
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
                                10000
                        ),
                    ],
                    reason:
                        'Un operador no posee autoridad para esta resolución económica.',
                    idempotencyKey:
                        'p83:operator'
                ),
                $operator
            )
        );

        $this->assertDatabaseCount(
            'commerce_post_sale_resolutions',
            1
        );
    }

    /**
     * @return array{
     *   \App\Models\CommercePostSaleRequest,
     *   \App\Models\CommercePostSaleReceiptLine,
     *   User,
     *   \App\Models\CommerceSale,
     *   Organization
     * }
     */
    private function receivedReturn(
        string $suffix,
        bool $withCustomer = true
    ): array {
        $organization =
            Organization::query()
                ->where('slug', 'sulu-tv')
                ->firstOrFail();

        $actor = $this->user(
            $organization,
            UserRole::Admin
        );

        $location =
            InventoryLocation::query()
                ->forOrganization(
                    $organization->id
                )
                ->active()
                ->orderBy('id')
                ->firstOrFail();

        $customer = $withCustomer
            ? BusinessParty::query()
                ->create([
                    'organization_id' =>
                        $organization->id,
                    'party_type' =>
                        BusinessParty::TYPE_PERSON,
                    'name' =>
                        'Cliente P8.3 '
                        .$suffix,
                ])
            : null;

        $category =
            ProductCategory::withoutEvents(
                fn () =>
                    ProductCategory::query()
                        ->firstOrCreate(
                            [
                                'slug' =>
                                    'post-sale-resolution-tests',
                            ],
                            [
                                'name' =>
                                    'Resolución posventa',
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
                                'P83-'
                                .Str::upper(
                                    Str::random(8)
                                ),
                            'name' =>
                                'Producto P8.3 '
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
            'Precio P8.3.',
            $actor
        );

        $stock = app(
            InventoryMovementCreator::class
        )->create(
            new InventoryMovementDraftData(
                type:
                    InventoryMovementType::Receipt,
                effectiveAt:
                    CarbonImmutable::now(),
                reason:
                    'Stock previo P8.3.',
                idempotencyKey:
                    'p83:stock:'.$suffix
                    .':'.$actor->id,
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
            $actor
        );

        app(
            InventoryMovementConfirmer::class
        )->confirm(
            $stock,
            $actor
        );

        $account = app(
            FinancialAccountManager::class
        )->create(
            'Banco P8.3 '
            .$suffix.' '
            .$actor->id,
            FinancialAccountType::BankAccount,
            'ARS',
            $actor,
            'Banco'
        );

        $sale = app(
            CommerceCheckoutManager::class
        )->checkout(
            new CommerceCheckoutData(
                currencyCode: 'ARS',
                idempotencyKey:
                    'p83:sale:'.$suffix
                    .':'.$actor->id,
                payments: [
                    new CommercePaymentData(
                        method:
                            CommercePaymentMethod::BankTransfer,
                        amountMinor: 20000,
                        reference:
                            'P83-'.$suffix,
                        financialAccountId:
                            $account->id
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
                        unitPriceMinor: 10000
                    ),
                ],
                customerBusinessPartyId:
                    $customer?->id
            ),
            $actor
        )->load(
            'lines.product',
            'payments'
        );

        $saleLine =
            $sale->lines->sole();

        $request = app(
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
                    'El cliente solicita devolver las unidades para resolución económica posterior.',
                idempotencyKey:
                    'p83:request:'.$suffix
                    .':'.$actor->id
            ),
            $actor
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
                        quantity: '2',
                        condition:
                            InventoryCondition::Used,
                        destinationLocationId:
                            $location->id,
                        notes:
                            'Mercadería físicamente recibida.'
                    ),
                ],
                idempotencyKey:
                    'p83:receipt:'.$suffix
                    .':'.$actor->id
            ),
            $actor
        );

        return [
            $request,
            $receipt->lines->sole(),
            $actor,
            $sale,
            $organization,
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
