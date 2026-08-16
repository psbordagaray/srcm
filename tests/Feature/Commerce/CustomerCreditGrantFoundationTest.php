<?php

namespace Tests\Feature\Commerce;

use App\Domain\Commerce\CommerceCheckoutData;
use App\Domain\Commerce\CommerceCheckoutManager;
use App\Domain\Commerce\CommercePaymentData;
use App\Domain\Commerce\CommercePostSaleCustomerCreditManager;
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
use App\Enums\InventoryLocationType;
use App\Enums\InventoryMovementType;
use App\Enums\UserRole;
use App\Models\BusinessParty;
use App\Models\CatalogProduct;
use App\Models\CommercePostSaleResolution;
use App\Models\CustomerCreditGrant;
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

class CustomerCreditGrantFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_schema_permissions_and_append_only_contract_are_explicit(): void
    {
        $this->assertTrue(
            Schema::hasColumns(
                'customer_credit_grants',
                [
                    'organization_id',
                    'public_id',
                    'business_party_id',
                    'commerce_post_sale_resolution_id',
                    'currency_code',
                    'amount_minor',
                    'granted_by_user_id',
                    'granted_at',
                    'idempotency_key',
                    'fingerprint',
                ]
            )
        );

        $this->assertTrue(
            UserRole::Admin
                ->canMaterializeCommercePostSaleCustomerCredit()
        );

        $this->assertFalse(
            UserRole::Operator
                ->canMaterializeCommercePostSaleCustomerCredit()
        );

        $this->assertFalse(
            UserRole::Viewer
                ->canMaterializeCommercePostSaleCustomerCredit()
        );
    }

    public function test_customer_credit_resolution_materializes_exact_grant_without_cash_or_payment_effect(): void
    {
        [
            $resolution,
            $actor,
            $sale,
            $party,
        ] = $this->customerCreditResolution(
            'grant'
        );

        $cashBefore =
            DB::table('cash_movements')->count();

        $inventoryBefore =
            DB::table('inventory_movements')
                ->count();

        $paymentsBefore =
            DB::table('commerce_payments')
                ->count();

        $grant = app(
            CommercePostSaleCustomerCreditManager::class
        )->grant(
            $resolution,
            'p841:grant',
            $actor
        );

        $this->assertSame(
            $party->id,
            $grant->business_party_id
        );

        $this->assertSame(
            $resolution->id,
            $grant
                ->commerce_post_sale_resolution_id
        );

        $this->assertSame(
            'ARS',
            $grant->currency_code
        );

        $this->assertSame(
            7000,
            $grant->amount_minor
        );

        $this->assertSame(
            $grant->id,
            $party
                ->refresh()
                ->customerCreditGrants()
                ->sole()
                ->id
        );

        $this->assertSame(
            $grant->id,
            $resolution
                ->refresh()
                ->customerCreditGrant
                ->id
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
            'financial_external_movements',
            0
        );

        $this->assertDatabaseHas(
            'audit_logs',
            [
                'event' =>
                    'customer_credit_granted_from_post_sale',
            ]
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
    }

    public function test_same_execution_is_idempotent_and_second_operation_fails_closed(): void
    {
        [
            $resolution,
            $actor,
        ] = $this->customerCreditResolution(
            'idem'
        );

        $manager = app(
            CommercePostSaleCustomerCreditManager::class
        );

        $first =
            $manager->grant(
                $resolution,
                'p841:idem',
                $actor
            );

        $second =
            $manager->grant(
                $resolution,
                'p841:idem',
                $actor
            );

        $this->assertSame(
            $first->id,
            $second->id
        );

        $this->assertDomainFailure(
            fn () => $manager->grant(
                $resolution,
                'p841:other-operation',
                $actor
            )
        );

        $this->assertDatabaseCount(
            'customer_credit_grants',
            1
        );
    }

    public function test_refund_exchange_operator_and_foreign_resolution_fail_closed(): void
    {
        [
            $customerCreditResolution,
            $admin,
            ,
            ,
            $organization,
        ] = $this->customerCreditResolution(
            'guards'
        );

        $operator =
            $this->user(
                $organization,
                UserRole::Operator
            );

        $manager = app(
            CommercePostSaleCustomerCreditManager::class
        );

        $this->assertDomainFailure(
            fn () => $manager->grant(
                $customerCreditResolution,
                'p841:operator',
                $operator
            )
        );

        $refund =
            $this->resolutionWithOutcome(
                $customerCreditResolution,
                CommercePostSaleResolutionOutcome::Refund
            );

        $exchange =
            $this->resolutionWithOutcome(
                $customerCreditResolution,
                CommercePostSaleResolutionOutcome::Exchange
            );

        $this->assertDomainFailure(
            fn () => $manager->grant(
                $refund,
                'p841:refund',
                $admin
            )
        );

        $this->assertDomainFailure(
            fn () => $manager->grant(
                $exchange,
                'p841:exchange',
                $admin
            )
        );

        [
            $foreignResolution,
        ] = $this->customerCreditResolution(
            'foreign',
            separateOrganization: true
        );

        $this->assertDomainFailure(
            fn () => $manager->grant(
                $foreignResolution,
                'p841:foreign',
                $admin
            )
        );

        $this->assertDatabaseCount(
            'customer_credit_grants',
            0
        );
    }

    public function test_database_and_model_guards_preserve_granted_credit_fact(): void
    {
        [
            $resolution,
            $actor,
        ] = $this->customerCreditResolution(
            'immutability'
        );

        $grant = app(
            CommercePostSaleCustomerCreditManager::class
        )->grant(
            $resolution,
            'p841:immutability',
            $actor
        );

        $this->assertQueryRejected(
            fn () => DB::table(
                'customer_credit_grants'
            )
                ->where('id', $grant->id)
                ->update([
                    'amount_minor' => 1,
                ])
        );

        $this->assertQueryRejected(
            fn () => DB::table(
                'customer_credit_grants'
            )
                ->where('id', $grant->id)
                ->delete()
        );

        $this->assertDomainFailure(
            function () use ($grant): void {
                $model =
                    CustomerCreditGrant::query()
                        ->findOrFail(
                            $grant->id
                        );

                $model->amount_minor = 1;
                $model->save();
            }
        );
    }

    /**
     * @return array{
     *   CommercePostSaleResolution,
     *   User,
     *   \App\Models\CommerceSale,
     *   BusinessParty,
     *   Organization
     * }
     */
    private function customerCreditResolution(
        string $suffix,
        bool $separateOrganization = false
    ): array {
        $organization =
            $separateOrganization
                ? Organization::query()
                    ->create([
                        'name' =>
                            'P8.4.1 Org '.$suffix,
                        'slug' =>
                            'p841-org-'.$suffix
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

        $actor =
            $this->user(
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
                            'Recepción P8.4.1 '
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

        $party =
            BusinessParty::query()
                ->create([
                    'organization_id' =>
                        $organization->id,
                    'party_type' =>
                        BusinessParty::TYPE_PERSON,
                    'name' =>
                        'Cliente P8.4.1 '
                        .$suffix,
                ]);

        $category =
            ProductCategory::withoutEvents(
                fn () =>
                    ProductCategory::query()
                        ->firstOrCreate(
                            [
                                'slug' =>
                                    'customer-credit-grant-tests',
                            ],
                            [
                                'name' =>
                                    'Saldo a favor posventa',
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
                                'P841-'
                                .Str::upper(
                                    Str::random(8)
                                ),
                            'name' =>
                                'Producto P8.4.1 '
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
            'Precio P8.4.1.',
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
                    'Stock previo P8.4.1.',
                idempotencyKey:
                    'p841:stock:'
                    .$suffix.':'
                    .$actor->id,
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
            'Banco P8.4.1 '
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
                    'p841:sale:'
                    .$suffix.':'
                    .$actor->id,
                payments: [
                    new CommercePaymentData(
                        method:
                            CommercePaymentMethod::BankTransfer,
                        amountMinor: 10000,
                        reference:
                            'P841-'.$suffix,
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
                        quantity: '1',
                        unitPriceMinor:
                            10000
                    ),
                ],
                customerBusinessPartyId:
                    $party->id
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
                        quantity: '1'
                    ),
                ],
                reason:
                    'El cliente solicita devolución para materializar luego saldo a favor.',
                idempotencyKey:
                    'p841:request:'
                    .$suffix.':'
                    .$actor->id
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
                        quantity: '1',
                        condition:
                            InventoryCondition::Used,
                        destinationLocationId:
                            $location->id
                    ),
                ],
                idempotencyKey:
                    'p841:receipt:'
                    .$suffix.':'
                    .$actor->id
            ),
            $actor
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
                    CommercePostSaleResolutionOutcome::CustomerCredit,
                lines: [
                    new CommercePostSaleResolutionLineData(
                        commercePostSaleReceiptLineId:
                            $receiptLine->id,
                        quantity: '1',
                        recognizedAmountMinor:
                            7000,
                        adjustmentReason:
                            'La unidad volvió usada y se reconoce un valor comercial menor.'
                    ),
                ],
                reason:
                    'Se autoriza saldo a favor por la mercadería físicamente recibida.',
                idempotencyKey:
                    'p841:resolution:'
                    .$suffix.':'
                    .$actor->id
            ),
            $actor
        );

        return [
            $resolution,
            $actor,
            $sale,
            $party,
            $organization,
        ];
    }

    private function resolutionWithOutcome(
        CommercePostSaleResolution $source,
        CommercePostSaleResolutionOutcome $outcome
    ): CommercePostSaleResolution {
        $clone =
            $source->replicate();

        $clone->public_id =
            (string) Str::uuid();
        $clone->outcome =
            $outcome;
        $clone->idempotency_key =
            'p841:synthetic:'
            .$outcome->value.':'
            .Str::lower(
                Str::random(6)
            );
        $clone->fingerprint =
            hash(
                'sha256',
                $clone
                    ->idempotency_key
            );

        CommercePostSaleResolution::withoutEvents(
            fn () => $clone->save()
        );

        return $clone->refresh();
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
