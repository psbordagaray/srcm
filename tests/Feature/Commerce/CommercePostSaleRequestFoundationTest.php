<?php

namespace Tests\Feature\Commerce;

use App\Domain\Commerce\CommerceCheckoutData;
use App\Domain\Commerce\CommerceCheckoutManager;
use App\Domain\Commerce\CommercePaymentData;
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
use App\Enums\InventoryMovementType;
use App\Enums\UserRole;
use App\Models\BusinessParty;
use App\Models\CatalogProduct;
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

class CommercePostSaleRequestFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_schema_permissions_and_append_only_contract_are_explicit(): void
    {
        $this->assertTrue(Schema::hasColumns(
            'commerce_post_sale_requests',
            [
                'organization_id',
                'public_id',
                'commerce_sale_id',
                'intent',
                'reason',
                'requested_by_user_id',
                'requested_at',
                'idempotency_key',
                'fingerprint',
            ]
        ));

        $this->assertTrue(Schema::hasColumns(
            'commerce_post_sale_request_lines',
            [
                'organization_id',
                'commerce_post_sale_request_id',
                'commerce_sale_line_id',
                'quantity',
            ]
        ));

        $this->assertTrue(
            UserRole::Admin->canRecordCommercePostSaleRequest()
        );
        $this->assertTrue(
            UserRole::Operator->canRecordCommercePostSaleRequest()
        );
        $this->assertFalse(
            UserRole::Viewer->canRecordCommercePostSaleRequest()
        );
        $this->assertTrue(
            UserRole::Viewer->canViewCommercePostSaleRequests()
        );
    }

    public function test_partial_request_traces_original_sale_without_stock_or_payment_effect(): void
    {
        [$sale, $actor] = $this->productSale('partial');
        $line = $sale->lines->sole();

        $inventoryBefore = DB::table('inventory_movements')->count();
        $paymentsBefore = DB::table('commerce_payments')->count();

        $request = app(
            CommercePostSaleRequestManager::class
        )->create(
            new CommercePostSaleRequestData(
                commerceSaleId: $sale->id,
                intent: CommercePostSaleIntent::Return,
                lines: [
                    new CommercePostSaleRequestLineData(
                        $line->id,
                        '1'
                    ),
                ],
                reason:
                    'El cliente solicita devolver una unidad para revisión posterior.',
                idempotencyKey:
                    'p8:request:partial'
            ),
            $actor
        );

        $this->assertSame(
            $sale->id,
            $request->commerce_sale_id
        );
        $this->assertSame(
            CommercePostSaleIntent::Return,
            $request->intent
        );
        $this->assertSame(
            '1.000000',
            $request->lines->sole()->quantity
        );

        $this->assertSame(
            CommerceSaleStatus::Confirmed,
            $sale->fresh()->status
        );
        $this->assertSame(
            '2.000000',
            $line->fresh()->quantity
        );
        $this->assertSame(
            $inventoryBefore,
            DB::table('inventory_movements')->count()
        );
        $this->assertSame(
            $paymentsBefore,
            DB::table('commerce_payments')->count()
        );
        $this->assertDatabaseHas(
            'audit_logs',
            [
                'event' =>
                    'commerce_post_sale_request_recorded',
            ]
        );
    }

    public function test_exchange_request_is_idempotent_and_conflicting_reuse_fails_closed(): void
    {
        [$sale, $actor] = $this->productSale('idem');
        $line = $sale->lines->sole();

        $data = new CommercePostSaleRequestData(
            commerceSaleId: $sale->id,
            intent: CommercePostSaleIntent::Exchange,
            lines: [
                new CommercePostSaleRequestLineData(
                    $line->id,
                    '2'
                ),
            ],
            reason:
                'El cliente solicita cambiar las dos unidades vendidas.',
            idempotencyKey:
                'p8:request:idem'
        );

        $manager = app(
            CommercePostSaleRequestManager::class
        );

        $first = $manager->create($data, $actor);
        $second = $manager->create($data, $actor);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount(
            'commerce_post_sale_requests',
            1
        );

        $this->assertDomainFailure(
            fn () => $manager->create(
                new CommercePostSaleRequestData(
                    commerceSaleId: $sale->id,
                    intent:
                        CommercePostSaleIntent::Return,
                    lines: [
                        new CommercePostSaleRequestLineData(
                            $line->id,
                            '2'
                        ),
                    ],
                    reason:
                        'La misma clave intenta registrar otra decisión de posventa.',
                    idempotencyKey:
                        'p8:request:idem'
                ),
                $actor
            )
        );
    }

    public function test_quantity_other_sale_line_and_viewer_fail_closed(): void
    {
        [$sale, $actor, $organization] =
            $this->productSale('guards-a');

        [$otherSale] = $this->productSale('guards-b');

        $line = $sale->lines->sole();
        $otherLine = $otherSale->lines->sole();

        $manager = app(
            CommercePostSaleRequestManager::class
        );

        $this->assertDomainFailure(
            fn () => $manager->create(
                new CommercePostSaleRequestData(
                    commerceSaleId: $sale->id,
                    intent:
                        CommercePostSaleIntent::Return,
                    lines: [
                        new CommercePostSaleRequestLineData(
                            $line->id,
                            '3'
                        ),
                    ],
                    reason:
                        'La cantidad supera lo vendido y debe ser rechazada.',
                    idempotencyKey:
                        'p8:request:too-many'
                ),
                $actor
            )
        );

        $this->assertDomainFailure(
            fn () => $manager->create(
                new CommercePostSaleRequestData(
                    commerceSaleId: $sale->id,
                    intent:
                        CommercePostSaleIntent::Return,
                    lines: [
                        new CommercePostSaleRequestLineData(
                            $otherLine->id,
                            '1'
                        ),
                    ],
                    reason:
                        'La línea pertenece a otra venta y debe ser rechazada.',
                    idempotencyKey:
                        'p8:request:other-sale'
                ),
                $actor
            )
        );

        $viewer = $this->user(
            $organization,
            UserRole::Viewer
        );

        $this->assertDomainFailure(
            fn () => $manager->create(
                new CommercePostSaleRequestData(
                    commerceSaleId: $sale->id,
                    intent:
                        CommercePostSaleIntent::Return,
                    lines: [
                        new CommercePostSaleRequestLineData(
                            $line->id,
                            '1'
                        ),
                    ],
                    reason:
                        'El usuario de consulta no puede registrar posventa.',
                    idempotencyKey:
                        'p8:request:viewer'
                ),
                $viewer
            )
        );

        $this->assertDatabaseCount(
            'commerce_post_sale_requests',
            0
        );
    }

    public function test_database_and_model_guards_preserve_request_line_and_original_sale(): void
    {
        [$sale, $actor] = $this->productSale('db');
        $line = $sale->lines->sole();

        $request = app(
            CommercePostSaleRequestManager::class
        )->create(
            new CommercePostSaleRequestData(
                commerceSaleId: $sale->id,
                intent: CommercePostSaleIntent::Return,
                lines: [
                    new CommercePostSaleRequestLineData(
                        $line->id,
                        '1'
                    ),
                ],
                reason:
                    'Solicitud válida antes del intento de manipulación directa.',
                idempotencyKey:
                    'p8:request:db'
            ),
            $actor
        );

        $this->assertQueryRejected(
            fn () => DB::table(
                'commerce_post_sale_requests'
            )
                ->where('id', $request->id)
                ->update([
                    'reason' =>
                        'Manipulación posterior',
                ])
        );

        $this->assertQueryRejected(
            fn () => DB::table(
                'commerce_post_sale_request_lines'
            )
                ->where(
                    'commerce_post_sale_request_id',
                    $request->id
                )
                ->update([
                    'quantity' => '2.000000',
                ])
        );

        $this->assertDomainFailure(
            function () use ($sale): void {
                $sale->notes =
                    'Intento retrospectivo';
                $sale->save();
            }
        );

        $this->assertSame(
            CommerceSaleStatus::Confirmed,
            $sale->fresh()->status
        );
    }

    /**
     * @return array{
     *   \App\Models\CommerceSale,
     *   User,
     *   Organization
     * }
     */
    private function productSale(string $suffix): array
    {
        $organization = Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();

        $actor = $this->user(
            $organization,
            UserRole::Admin
        );

        $location = InventoryLocation::query()
            ->forOrganization($organization->id)
            ->orderBy('id')
            ->firstOrFail();

        $customer = BusinessParty::query()->create([
            'organization_id' => $organization->id,
            'party_type' => BusinessParty::TYPE_PERSON,
            'name' => 'Cliente P8 '.$suffix,
        ]);

        $category = ProductCategory::withoutEvents(
            fn () => ProductCategory::query()->firstOrCreate(
                ['slug' => 'post-sale-tests'],
                [
                    'name' => 'Posventa',
                    'active' => true,
                ]
            )
        );

        $product = CatalogProduct::withoutEvents(
            fn () => CatalogProduct::query()->create([
                'product_category_id' => $category->id,
                'sku' => 'P8-'.Str::upper(
                    Str::random(8)
                ),
                'name' => 'Producto P8 '.$suffix,
                'active' => true,
            ])->refresh()
        );

        app(
            OrganizationProductPriceManager::class
        )->set(
            $product,
            'ARS',
            10000,
            'Precio de prueba P8.1.',
            $actor
        );

        $stock = app(
            InventoryMovementCreator::class
        )->create(
            new InventoryMovementDraftData(
                type: InventoryMovementType::Receipt,
                effectiveAt: CarbonImmutable::now(),
                reason: 'Stock previo P8.1.',
                idempotencyKey:
                    'p8:stock:'.$suffix.':'
                    .$actor->id,
                lines: [
                    new InventoryMovementLineData(
                        catalogProductId:
                            $product->id,
                        condition:
                            InventoryCondition::New,
                        enteredQuantity: '2',
                        enteredUnitCode:
                            $product->base_unit_code,
                        destinationLocationId:
                            $location->id
                    ),
                ]
            ),
            $actor
        );

        app(
            InventoryMovementConfirmer::class
        )->confirm($stock, $actor);

        $account = app(
            FinancialAccountManager::class
        )->create(
            'Banco P8 '.$suffix.' '.$actor->id,
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
                    'p8:sale:'.$suffix.':'
                    .$actor->id,
                payments: [
                    new CommercePaymentData(
                        method:
                            CommercePaymentMethod::BankTransfer,
                        amountMinor: 20000,
                        reference:
                            'P8-'.$suffix,
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
                    $customer->id
            ),
            $actor
        );

        return [
            $sale->load('lines.product'),
            $actor,
            $organization,
        ];
    }

    private function user(
        Organization $organization,
        UserRole $role
    ): User {
        $user = User::factory()->create([
            'role' => $role,
            'current_organization_id' =>
                $organization->id,
            'email_verified_at' => now(),
        ]);

        OrganizationMembership::query()->updateOrCreate(
            [
                'organization_id' =>
                    $organization->id,
                'user_id' => $user->id,
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
