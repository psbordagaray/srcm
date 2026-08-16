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
use App\Enums\CommerceSaleStatus;
use App\Enums\FinancialAccountType;
use App\Enums\InventoryCondition;
use App\Enums\InventoryLocationType;
use App\Enums\InventoryMovementStatus;
use App\Enums\InventoryMovementType;
use App\Enums\UserRole;
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
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class CommercePostSaleReceiptFoundationTest
    extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_schema_permissions_and_trace_columns_are_explicit(): void
    {
        $this->assertTrue(
            Schema::hasColumns(
                'commerce_post_sale_receipts',
                [
                    'organization_id',
                    'public_id',
                    'commerce_post_sale_request_id',
                    'inventory_movement_id',
                    'received_by_user_id',
                    'received_at',
                    'idempotency_key',
                    'fingerprint',
                ]
            )
        );

        $this->assertTrue(
            Schema::hasColumns(
                'commerce_post_sale_receipt_lines',
                [
                    'organization_id',
                    'commerce_post_sale_receipt_id',
                    'commerce_post_sale_request_line_id',
                    'inventory_movement_line_id',
                    'quantity',
                    'condition',
                    'destination_location_id',
                ]
            )
        );

        $this->assertTrue(
            UserRole::Admin
                ->canReceiveCommercePostSaleReturn()
        );
        $this->assertTrue(
            UserRole::Operator
                ->canReceiveCommercePostSaleReturn()
        );
        $this->assertFalse(
            UserRole::Viewer
                ->canReceiveCommercePostSaleReturn()
        );
    }

    public function test_partial_physical_receipt_confirms_customer_return_with_real_condition_without_money_effect(): void
    {
        [
            $request,
            $actor,
            $location,
            $product,
            $sale,
        ] = $this->postSaleRequest(
            'partial'
        );

        $requestLine =
            $request->lines->sole();

        $inventoryBefore =
            DB::table(
                'inventory_movements'
            )->count();

        $paymentBefore =
            DB::table(
                'commerce_payments'
            )->count();

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
                            $location->id,
                        notes:
                            'Unidad recibida con marcas de uso.'
                    ),
                ],
                idempotencyKey:
                    'p8:receipt:partial',
                notes:
                    'Recepción física parcial.'
            ),
            $actor
        );

        $this->assertSame(
            $request->id,
            $receipt
                ->commerce_post_sale_request_id
        );

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

        $receiptLine =
            $receipt->lines->sole();

        $this->assertSame(
            InventoryCondition::Used,
            $receiptLine->condition
        );

        $this->assertSame(
            '1.000000',
            $receiptLine->quantity
        );

        $this->assertSame(
            $location->id,
            $receiptLine
                ->destination_location_id
        );

        $this->assertSame(
            $receipt
                ->inventoryMovement
                ->lines
                ->sole()
                ->id,
            $receiptLine
                ->inventory_movement_line_id
        );

        $balance = InventoryBalance::query()
            ->forOrganization(
                $actor
                    ->current_organization_id
            )
            ->where(
                'catalog_product_id',
                $product->id
            )
            ->where(
                'inventory_location_id',
                $location->id
            )
            ->where(
                'condition',
                InventoryCondition::Used->value
            )
            ->firstOrFail();

        $this->assertSame(
            '1.000000',
            $balance->quantity
        );

        $this->assertSame(
            $inventoryBefore + 1,
            DB::table(
                'inventory_movements'
            )->count()
        );

        $this->assertSame(
            $paymentBefore,
            DB::table(
                'commerce_payments'
            )->count()
        );

        $this->assertSame(
            CommerceSaleStatus::Confirmed,
            $sale->fresh()->status
        );

        $this->assertDatabaseCount(
            'payment_reconciliations',
            0
        );

        $this->assertDatabaseHas(
            'audit_logs',
            [
                'event' =>
                    'commerce_post_sale_receipt_confirmed',
            ]
        );
    }

    public function test_multiple_receipts_can_complete_request_but_cumulative_over_receipt_fails_closed(): void
    {
        [
            $request,
            $actor,
            $location,
        ] = $this->postSaleRequest(
            'cumulative'
        );

        $requestLine =
            $request->lines->sole();

        $manager = app(
            CommercePostSaleReceiptManager::class
        );

        $manager->receive(
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
                    'p8:receipt:cumulative:1'
            ),
            $actor
        );

        $manager->receive(
            new CommercePostSaleReceiptData(
                commercePostSaleRequestId:
                    $request->id,
                lines: [
                    new CommercePostSaleReceiptLineData(
                        commercePostSaleRequestLineId:
                            $requestLine->id,
                        quantity: '1',
                        condition:
                            InventoryCondition::Damaged,
                        destinationLocationId:
                            $location->id
                    ),
                ],
                idempotencyKey:
                    'p8:receipt:cumulative:2'
            ),
            $actor
        );

        $this->assertDomainFailure(
            fn () => $manager->receive(
                new CommercePostSaleReceiptData(
                    commercePostSaleRequestId:
                        $request->id,
                    lines: [
                        new CommercePostSaleReceiptLineData(
                            commercePostSaleRequestLineId:
                                $requestLine->id,
                            quantity: '1',
                            condition:
                                InventoryCondition::New,
                            destinationLocationId:
                                $location->id
                        ),
                    ],
                    idempotencyKey:
                        'p8:receipt:cumulative:3'
                ),
                $actor
            )
        );

        $this->assertDatabaseCount(
            'commerce_post_sale_receipts',
            2
        );

        $this->assertSame(
            2,
            DB::table(
                'inventory_movements'
            )
                ->where(
                    'type',
                    InventoryMovementType::CustomerReturn->value
                )
                ->count()
        );
    }

    public function test_receipt_is_idempotent_and_conflicting_reuse_fails_closed(): void
    {
        [
            $request,
            $actor,
            $location,
        ] = $this->postSaleRequest(
            'idem'
        );

        $requestLine =
            $request->lines->sole();

        $data =
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
                    'p8:receipt:idem'
            );

        $manager = app(
            CommercePostSaleReceiptManager::class
        );

        $first =
            $manager->receive(
                $data,
                $actor
            );

        $second =
            $manager->receive(
                $data,
                $actor
            );

        $this->assertSame(
            $first->id,
            $second->id
        );

        $this->assertSame(
            $first
                ->inventory_movement_id,
            $second
                ->inventory_movement_id
        );

        $this->assertDatabaseCount(
            'commerce_post_sale_receipts',
            1
        );

        $this->assertDomainFailure(
            fn () => $manager->receive(
                new CommercePostSaleReceiptData(
                    commercePostSaleRequestId:
                        $request->id,
                    lines: [
                        new CommercePostSaleReceiptLineData(
                            commercePostSaleRequestLineId:
                                $requestLine->id,
                            quantity: '1',
                            condition:
                                InventoryCondition::Damaged,
                            destinationLocationId:
                                $location->id
                        ),
                    ],
                    idempotencyKey:
                        'p8:receipt:idem'
                ),
                $actor
            )
        );

        $this->assertDatabaseCount(
            'commerce_post_sale_receipts',
            1
        );
    }

    public function test_viewer_foreign_location_and_foreign_request_fail_closed(): void
    {
        [
            $request,
            $actor,
            $location,
            ,
            ,
            $organization,
        ] = $this->postSaleRequest(
            'guards'
        );

        $requestLine =
            $request->lines->sole();

        $viewer = $this->user(
            $organization,
            UserRole::Viewer
        );

        $manager = app(
            CommercePostSaleReceiptManager::class
        );

        $this->assertDomainFailure(
            fn () => $manager->receive(
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
                        'p8:receipt:viewer'
                ),
                $viewer
            )
        );

        $foreignOrganization =
            Organization::query()->create([
                'name' =>
                    'P8.2 Foreign',
                'slug' =>
                    'p82-foreign-'
                    .Str::lower(
                        Str::random(6)
                    ),
                'active' => true,
            ]);

        $foreignLocation =
            InventoryLocation::query()
                ->create([
                    'organization_id' =>
                        $foreignOrganization->id,
                    'name' =>
                        'Recepción externa',
                    'type' =>
                        InventoryLocationType::Receiving,
                    'active' => true,
                ]);

        $this->assertDomainFailure(
            fn () => $manager->receive(
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
                                $foreignLocation->id
                        ),
                    ],
                    idempotencyKey:
                        'p8:receipt:foreign-location'
                ),
                $actor
            )
        );

        [$foreignRequest] =
            $this->postSaleRequest(
                'foreign-request',
                separateOrganization: true
            );

        $this->assertDomainFailure(
            fn () => $manager->receive(
                new CommercePostSaleReceiptData(
                    commercePostSaleRequestId:
                        $foreignRequest->id,
                    lines: [
                        new CommercePostSaleReceiptLineData(
                            commercePostSaleRequestLineId:
                                $foreignRequest
                                    ->lines
                                    ->sole()
                                    ->id,
                            quantity: '1',
                            condition:
                                InventoryCondition::Used,
                            destinationLocationId:
                                $location->id
                        ),
                    ],
                    idempotencyKey:
                        'p8:receipt:foreign-request'
                ),
                $actor
            )
        );

        $this->assertDatabaseCount(
            'commerce_post_sale_receipts',
            0
        );
    }

    public function test_model_and_database_guards_preserve_receipt_trace(): void
    {
        [
            $request,
            $actor,
            $location,
        ] = $this->postSaleRequest(
            'db'
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
                    'p8:receipt:db'
            ),
            $actor
        );

        $this->assertQueryRejected(
            fn () => DB::table(
                'commerce_post_sale_receipts'
            )
                ->where(
                    'id',
                    $receipt->id
                )
                ->update([
                    'notes' =>
                        'Manipulación posterior',
                ])
        );

        $this->assertQueryRejected(
            fn () => DB::table(
                'commerce_post_sale_receipt_lines'
            )
                ->where(
                    'commerce_post_sale_receipt_id',
                    $receipt->id
                )
                ->update([
                    'condition' =>
                        InventoryCondition::New->value,
                ])
        );

        $this->assertDomainFailure(
            function () use ($receipt): void {
                $model =
                    CommercePostSaleReceipt::query()
                        ->findOrFail(
                            $receipt->id
                        );

                $model->notes =
                    'Intento por modelo';
                $model->save();
            }
        );

        $this->assertSame(
            InventoryMovementStatus::Confirmed,
            $receipt
                ->inventoryMovement
                ->fresh()
                ->status
        );
    }

    /**
     * @return array{
     *   CommercePostSaleRequest,
     *   User,
     *   InventoryLocation,
     *   CatalogProduct,
     *   \App\Models\CommerceSale,
     *   Organization
     * }
     */
    private function postSaleRequest(
        string $suffix,
        bool $separateOrganization = false
    ): array {
        $organization =
            $separateOrganization
                ? Organization::query()
                    ->create([
                        'name' =>
                            'P8.2 Org '.$suffix,
                        'slug' =>
                            'p82-org-'.$suffix
                            .'-'
                            .Str::lower(
                                Str::random(5)
                            ),
                        'active' => true,
                    ])
                : Organization::query()
                    ->where(
                        'slug',
                        'sulu-tv'
                    )
                    ->firstOrFail();

        $actor = $this->user(
            $organization,
            UserRole::Admin
        );

        $location =
            $separateOrganization
                ? InventoryLocation::query()
                    ->create([
                        'organization_id' =>
                            $organization->id,
                        'name' =>
                            'Recepción P8.2 '
                            .$suffix,
                        'type' =>
                            InventoryLocationType::Receiving,
                        'active' => true,
                    ])
                : InventoryLocation::query()
                    ->forOrganization(
                        $organization->id
                    )
                    ->active()
                    ->orderBy('id')
                    ->firstOrFail();

        $customer =
            BusinessParty::query()
                ->create([
                    'organization_id' =>
                        $organization->id,
                    'party_type' =>
                        BusinessParty::TYPE_PERSON,
                    'name' =>
                        'Cliente P8.2 '
                        .$suffix,
                ]);

        $category =
            ProductCategory::withoutEvents(
                fn () =>
                    ProductCategory::query()
                        ->firstOrCreate(
                            [
                                'slug' =>
                                    'post-sale-receipt-tests',
                            ],
                            [
                                'name' =>
                                    'Recepción posventa',
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
                                'P82-'
                                .Str::upper(
                                    Str::random(8)
                                ),
                            'name' =>
                                'Producto P8.2 '
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
            'Precio P8.2.',
            $actor
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
                        'Stock previo P8.2.',
                    idempotencyKey:
                        'p82:stock:'
                        .$suffix.':'
                        .$actor->id,
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
            'Banco P8.2 '
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
                    'p82:sale:'
                    .$suffix.':'
                    .$actor->id,
                payments: [
                    new CommercePaymentData(
                        method:
                            CommercePaymentMethod::BankTransfer,
                        amountMinor:
                            20000,
                        reference:
                            'P82-'.$suffix,
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
                        unitPriceMinor:
                            10000
                    ),
                ],
                customerBusinessPartyId:
                    $customer->id
            ),
            $actor
        )->load('lines.product');

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
                    'El cliente solicita devolver las unidades vendidas para recepción física.',
                idempotencyKey:
                    'p82:request:'
                    .$suffix.':'
                    .$actor->id
            ),
            $actor
        );

        return [
            $request,
            $actor,
            $location,
            $product,
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
