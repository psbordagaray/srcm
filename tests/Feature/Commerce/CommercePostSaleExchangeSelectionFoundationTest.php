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
use App\Enums\InventoryLocationType;
use App\Enums\InventoryMovementType;
use App\Enums\UserRole;
use App\Models\BusinessParty;
use App\Models\CatalogProduct;
use App\Models\CommercePostSaleExchangeSelection;
use App\Models\CommercePostSaleExchangeSelectionLine;
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

class CommercePostSaleExchangeSelectionFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_schema_and_relation_are_explicit(): void
    {
        $this->assertTrue(
            Schema::hasColumns(
                'commerce_post_sale_exchange_selections',
                [
                    'organization_id',
                    'public_id',
                    'commerce_post_sale_resolution_id',
                    'currency_code',
                    'recognized_amount_minor',
                    'selected_by_user_id',
                    'selected_at',
                    'notes',
                    'idempotency_key',
                    'fingerprint',
                ]
            )
        );

        $this->assertTrue(
            Schema::hasColumns(
                'commerce_post_sale_exchange_selection_lines',
                [
                    'organization_id',
                    'commerce_post_sale_exchange_selection_id',
                    'sequence',
                    'catalog_product_id',
                    'organization_product_price_id',
                    'quantity',
                    'unit_price_minor',
                    'line_amount_minor',
                    'created_at',
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
    }

    public function test_exchange_selection_snapshots_authorized_price_and_positive_difference_without_side_effects(): void
    {
        [
            $resolution,
            $actor,
            $replacement,
        ] = $this->exchangeContext(
            'positive',
            replacementPriceMinor: 9000
        );

        $cashBefore =
            DB::table('cash_movements')->count();

        $inventoryBefore =
            DB::table('inventory_movements')->count();

        $paymentsBefore =
            DB::table('commerce_payments')->count();

        $externalBefore =
            DB::table('financial_external_movements')->count();

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
                    'p844:selection:positive',
                notes:
                    'Cliente elige reemplazo de mayor valor.'
            ),
            $actor
        );

        $this->assertSame(
            $resolution->id,
            $selection
                ->commerce_post_sale_resolution_id
        );

        $this->assertSame(
            'ARS',
            $selection->currency_code
        );

        $this->assertSame(
            7000,
            $selection->recognized_amount_minor
        );

        $this->assertSame(
            9000,
            $selection->replacementAmountMinor()
        );

        $this->assertSame(
            2000,
            $selection->differenceAmountMinor()
        );

        $line = $selection->lines->sole();

        $this->assertSame(
            $replacement->id,
            $line->catalog_product_id
        );

        $this->assertSame(
            9000,
            $line->unit_price_minor
        );

        $this->assertSame(
            9000,
            $line->line_amount_minor
        );

        $this->assertNotNull(
            $line->organization_product_price_id
        );

        $this->assertSame(
            $selection->id,
            $resolution
                ->refresh()
                ->exchangeSelection
                ->id
        );

        $this->assertSame(
            $cashBefore,
            DB::table('cash_movements')->count()
        );

        $this->assertSame(
            $inventoryBefore,
            DB::table('inventory_movements')->count()
        );

        $this->assertSame(
            $paymentsBefore,
            DB::table('commerce_payments')->count()
        );

        $this->assertSame(
            $externalBefore,
            DB::table(
                'financial_external_movements'
            )->count()
        );

        $this->assertDatabaseCount(
            'customer_credit_grants',
            0
        );

        $this->assertDatabaseCount(
            'commerce_post_sale_cash_refund_executions',
            0
        );

        $this->assertDatabaseCount(
            'commerce_post_sale_external_refund_instructions',
            0
        );

        $this->assertDatabaseHas(
            'audit_logs',
            [
                'event' =>
                    'commerce_post_sale_exchange_replacement_selected',
            ]
        );
    }

    public function test_negative_and_zero_difference_are_derived_without_moving_money(): void
    {
        [
            $negativeResolution,
            $negativeActor,
            $cheaper,
        ] = $this->exchangeContext(
            'negative',
            replacementPriceMinor: 5000
        );

        $negative = app(
            CommercePostSaleExchangeSelectionManager::class
        )->select(
            $negativeResolution,
            new CommercePostSaleExchangeSelectionData(
                lines: [
                    new CommercePostSaleExchangeSelectionLineData(
                        catalogProductId:
                            $cheaper->id,
                        quantity: '1'
                    ),
                ],
                idempotencyKey:
                    'p844:selection:negative'
            ),
            $negativeActor
        );

        $this->assertSame(
            -2000,
            $negative->differenceAmountMinor()
        );

        [
            $zeroResolution,
            $zeroActor,
            $even,
        ] = $this->exchangeContext(
            'zero',
            replacementPriceMinor: 7000
        );

        $zero = app(
            CommercePostSaleExchangeSelectionManager::class
        )->select(
            $zeroResolution,
            new CommercePostSaleExchangeSelectionData(
                lines: [
                    new CommercePostSaleExchangeSelectionLineData(
                        catalogProductId:
                            $even->id,
                        quantity: '1'
                    ),
                ],
                idempotencyKey:
                    'p844:selection:zero'
            ),
            $zeroActor
        );

        $this->assertSame(
            0,
            $zero->differenceAmountMinor()
        );

        $this->assertDatabaseCount(
            'financial_external_movements',
            0
        );
    }

    public function test_multiple_replacement_lines_sum_exactly_and_preserve_price_provenance(): void
    {
        [
            $resolution,
            $actor,
            $replacement,
            $organization,
        ] = $this->exchangeContext(
            'multiple',
            replacementPriceMinor: 3000
        );

        $second =
            $this->replacementProduct(
                $organization,
                $actor,
                'multiple-second',
                2500
            );

        $selection = app(
            CommercePostSaleExchangeSelectionManager::class
        )->select(
            $resolution,
            new CommercePostSaleExchangeSelectionData(
                lines: [
                    new CommercePostSaleExchangeSelectionLineData(
                        catalogProductId:
                            $second->id,
                        quantity: '1'
                    ),
                    new CommercePostSaleExchangeSelectionLineData(
                        catalogProductId:
                            $replacement->id,
                        quantity: '1'
                    ),
                ],
                idempotencyKey:
                    'p844:selection:multiple'
            ),
            $actor
        );

        $this->assertSame(
            5500,
            $selection->replacementAmountMinor()
        );

        $this->assertSame(
            -1500,
            $selection->differenceAmountMinor()
        );

        $this->assertCount(
            2,
            $selection->lines
        );

        $this->assertSame(
            [
                $replacement->id,
                $second->id,
            ],
            $selection->lines
                ->pluck('catalog_product_id')
                ->sort()
                ->values()
                ->all()
        );

        foreach ($selection->lines as $line) {
            $this->assertNotNull(
                $line->price
            );

            $this->assertSame(
                $line->catalog_product_id,
                $line->price
                    ->catalog_product_id
            );

            $this->assertSame(
                $line->unit_price_minor,
                $line->price
                    ->amount_minor
            );
        }
    }

    public function test_same_selection_is_idempotent_and_second_operation_fails_closed(): void
    {
        [
            $resolution,
            $actor,
            $replacement,
        ] = $this->exchangeContext(
            'idem',
            replacementPriceMinor: 9000
        );

        $manager = app(
            CommercePostSaleExchangeSelectionManager::class
        );

        $data =
            new CommercePostSaleExchangeSelectionData(
                lines: [
                    new CommercePostSaleExchangeSelectionLineData(
                        catalogProductId:
                            $replacement->id,
                        quantity: '1'
                    ),
                ],
                idempotencyKey:
                    'p844:selection:idem',
                notes:
                    'Selección idempotente.'
            );

        $first =
            $manager->select(
                $resolution,
                $data,
                $actor
            );

        $second =
            $manager->select(
                $resolution,
                $data,
                $actor
            );

        $this->assertSame(
            $first->id,
            $second->id
        );

        $this->assertDomainFailure(
            fn () => $manager->select(
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
                        'p844:selection:another'
                ),
                $actor
            )
        );

        $this->assertDatabaseCount(
            'commerce_post_sale_exchange_selections',
            1
        );
    }

    public function test_non_exchange_operator_foreign_resolution_and_missing_price_fail_closed(): void
    {
        [
            $resolution,
            $admin,
            $replacement,
            $organization,
        ] = $this->exchangeContext(
            'guards',
            replacementPriceMinor: 9000
        );

        $manager = app(
            CommercePostSaleExchangeSelectionManager::class
        );

        $operator =
            $this->user(
                $organization,
                UserRole::Operator
            );

        $this->assertDomainFailure(
            fn () => $manager->select(
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
                        'p844:operator'
                ),
                $operator
            )
        );

        $notExchange =
            $this->resolutionWithOutcome(
                $resolution,
                CommercePostSaleResolutionOutcome::Refund
            );

        $this->assertDomainFailure(
            fn () => $manager->select(
                $notExchange,
                new CommercePostSaleExchangeSelectionData(
                    lines: [
                        new CommercePostSaleExchangeSelectionLineData(
                            catalogProductId:
                                $replacement->id,
                            quantity: '1'
                        ),
                    ],
                    idempotencyKey:
                        'p844:not-exchange'
                ),
                $admin
            )
        );

        [
            $foreignResolution,
            ,
            $foreignReplacement,
        ] = $this->exchangeContext(
            'foreign',
            replacementPriceMinor: 9000,
            separateOrganization: true
        );

        $this->assertDomainFailure(
            fn () => $manager->select(
                $foreignResolution,
                new CommercePostSaleExchangeSelectionData(
                    lines: [
                        new CommercePostSaleExchangeSelectionLineData(
                            catalogProductId:
                                $foreignReplacement->id,
                            quantity: '1'
                        ),
                    ],
                    idempotencyKey:
                        'p844:foreign'
                ),
                $admin
            )
        );

        $noPrice =
            $this->replacementProduct(
                $organization,
                $admin,
                'no-price',
                null
            );

        $this->assertDomainFailure(
            fn () => $manager->select(
                $resolution,
                new CommercePostSaleExchangeSelectionData(
                    lines: [
                        new CommercePostSaleExchangeSelectionLineData(
                            catalogProductId:
                                $noPrice->id,
                            quantity: '1'
                        ),
                    ],
                    idempotencyKey:
                        'p844:no-price'
                ),
                $admin
            )
        );

        $this->assertDatabaseCount(
            'commerce_post_sale_exchange_selections',
            0
        );
    }

    public function test_duplicate_product_and_quantity_precision_fail_closed(): void
    {
        [
            $resolution,
            $actor,
            $replacement,
        ] = $this->exchangeContext(
            'quantity',
            replacementPriceMinor: 9000
        );

        $manager = app(
            CommercePostSaleExchangeSelectionManager::class
        );

        $this->assertDomainFailure(
            fn () => $manager->select(
                $resolution,
                new CommercePostSaleExchangeSelectionData(
                    lines: [
                        new CommercePostSaleExchangeSelectionLineData(
                            catalogProductId:
                                $replacement->id,
                            quantity: '1'
                        ),
                        new CommercePostSaleExchangeSelectionLineData(
                            catalogProductId:
                                $replacement->id,
                            quantity: '1'
                        ),
                    ],
                    idempotencyKey:
                        'p844:duplicate'
                ),
                $actor
            )
        );

        $this->assertDomainFailure(
            fn () => $manager->select(
                $resolution,
                new CommercePostSaleExchangeSelectionData(
                    lines: [
                        new CommercePostSaleExchangeSelectionLineData(
                            catalogProductId:
                                $replacement->id,
                            quantity: '0.5'
                        ),
                    ],
                    idempotencyKey:
                        'p844:fraction'
                ),
                $actor
            )
        );

        $this->assertDatabaseCount(
            'commerce_post_sale_exchange_selections',
            0
        );
    }

    public function test_database_and_model_guards_preserve_selection_and_lines(): void
    {
        [
            $resolution,
            $actor,
            $replacement,
        ] = $this->exchangeContext(
            'immutability',
            replacementPriceMinor: 9000
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
                    'p844:immutability'
            ),
            $actor
        );

        $line = $selection->lines->sole();

        $this->assertQueryRejected(
            fn () => DB::table(
                'commerce_post_sale_exchange_selections'
            )
                ->where('id', $selection->id)
                ->update([
                    'recognized_amount_minor' => 1,
                ])
        );

        $this->assertQueryRejected(
            fn () => DB::table(
                'commerce_post_sale_exchange_selection_lines'
            )
                ->where('id', $line->id)
                ->update([
                    'line_amount_minor' => 1,
                ])
        );

        $this->assertQueryRejected(
            fn () => DB::table(
                'commerce_post_sale_exchange_selection_lines'
            )
                ->where('id', $line->id)
                ->delete()
        );

        $this->assertQueryRejected(
            fn () => DB::table(
                'commerce_post_sale_exchange_selections'
            )
                ->where('id', $selection->id)
                ->delete()
        );

        $this->assertDomainFailure(
            function () use ($selection): void {
                $model =
                    CommercePostSaleExchangeSelection::query()
                        ->findOrFail(
                            $selection->id
                        );

                $model->notes = 'mutación';
                $model->save();
            }
        );

        $this->assertDomainFailure(
            function () use ($line): void {
                $model =
                    CommercePostSaleExchangeSelectionLine::query()
                        ->findOrFail(
                            $line->id
                        );

                $model->line_amount_minor = 1;
                $model->save();
            }
        );
    }

    /**
     * @return array{
     *   CommercePostSaleResolution,
     *   User,
     *   CatalogProduct,
     *   Organization
     * }
     */
    private function exchangeContext(
        string $suffix,
        int $replacementPriceMinor,
        bool $separateOrganization = false
    ): array {
        $organization =
            $separateOrganization
                ? Organization::query()
                    ->create([
                        'name' =>
                            'P8.4.4 Org '.$suffix,
                        'slug' =>
                            'p844-org-'.$suffix
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
                            'Recepción P8.4.4 '
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
                        'Cliente P8.4.4 '
                        .$suffix,
                ]);

        $category =
            ProductCategory::withoutEvents(
                fn () =>
                    ProductCategory::query()
                        ->firstOrCreate(
                            [
                                'slug' =>
                                    'exchange-selection-tests',
                            ],
                            [
                                'name' =>
                                    'Cambio posventa',
                                'active' => true,
                            ]
                        )
            );

        $original =
            CatalogProduct::withoutEvents(
                fn () =>
                    CatalogProduct::query()
                        ->create([
                            'product_category_id' =>
                                $category->id,
                            'sku' =>
                                'P844-O-'
                                .Str::upper(
                                    Str::random(8)
                                ),
                            'name' =>
                                'Original P8.4.4 '
                                .$suffix,
                            'active' => true,
                        ])
                        ->refresh()
            );

        app(
            OrganizationProductPriceManager::class
        )->set(
            $original,
            'ARS',
            10000,
            'Precio original P8.4.4.',
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
                    'Stock previo P8.4.4.',
                idempotencyKey:
                    'p844:stock:'
                    .$suffix.':'
                    .$actor->id,
                lines: [
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
            'Banco P8.4.4 '
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
                    'p844:sale:'
                    .$suffix.':'
                    .$actor->id,
                payments: [
                    new CommercePaymentData(
                        method:
                            CommercePaymentMethod::BankTransfer,
                        amountMinor: 10000,
                        reference:
                            'P844-'.$suffix,
                        financialAccountId:
                            $account->id
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
            $actor
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
                    'El cliente solicita cambio y entrega la unidad original para evaluación.',
                idempotencyKey:
                    'p844:request:'
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
                    'p844:receipt:'
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
                    CommercePostSaleResolutionOutcome::Exchange,
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
                    'Se autoriza cambio por la mercadería físicamente recibida y evaluada.',
                idempotencyKey:
                    'p844:resolution:'
                    .$suffix.':'
                    .$actor->id
            ),
            $actor
        );

        $replacement =
            $this->replacementProduct(
                $organization,
                $actor,
                $suffix,
                $replacementPriceMinor
            );

        return [
            $resolution,
            $actor,
            $replacement,
            $organization,
        ];
    }

    private function replacementProduct(
        Organization $organization,
        User $actor,
        string $suffix,
        ?int $priceMinor
    ): CatalogProduct {
        $category =
            ProductCategory::withoutEvents(
                fn () =>
                    ProductCategory::query()
                        ->firstOrCreate(
                            [
                                'slug' =>
                                    'exchange-selection-tests',
                            ],
                            [
                                'name' =>
                                    'Cambio posventa',
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
                                'P844-R-'
                                .Str::upper(
                                    Str::random(8)
                                ),
                            'name' =>
                                'Reemplazo P8.4.4 '
                                .$suffix,
                            'active' => true,
                        ])
                        ->refresh()
            );

        if ($priceMinor !== null) {
            app(
                OrganizationProductPriceManager::class
            )->set(
                $product,
                'ARS',
                $priceMinor,
                'Precio reemplazo P8.4.4.',
                $actor
            );
        }

        return $product;
    }

    private function resolutionWithOutcome(
        CommercePostSaleResolution $source,
        CommercePostSaleResolutionOutcome $outcome
    ): CommercePostSaleResolution {
        $clone =
            $source->replicate();

        $clone->public_id =
            (string) Str::uuid();

        $clone->outcome = $outcome;

        $clone->idempotency_key =
            'p844:synthetic:'
            .$outcome->value.':'
            .Str::lower(
                Str::random(6)
            );

        $clone->fingerprint =
            hash(
                'sha256',
                $clone->idempotency_key
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
