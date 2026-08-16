<?php

namespace Tests\Feature\Commerce;

use App\Contracts\Finance\FinancialProviderRefundAdapter;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Commerce\CommerceCheckoutData;
use App\Domain\Commerce\CommerceCheckoutManager;
use App\Domain\Commerce\CommercePaymentData;
use App\Domain\Commerce\CommercePostSaleExternalRefundInstructionManager;
use App\Domain\Commerce\CommercePostSaleExternalRefundSubmissionManager;
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
use App\Domain\Finance\ExternalFinancialProviderIngestor;
use App\Domain\Finance\ExternalFinancialProviderObservation;
use App\Domain\Finance\FinancialAccountManager;
use App\Domain\Finance\FinancialProviderAutomationGate;
use App\Domain\Finance\FinancialProviderCompatibilityRegistry;
use App\Domain\Finance\FinancialProviderConnectionCompatibilityManager;
use App\Domain\Finance\FinancialProviderConnectionHealthManager;
use App\Domain\Finance\FinancialProviderConnectionManager;
use App\Domain\Finance\FinancialProviderHealthObservation;
use App\Domain\Finance\FinancialProviderRefundAdapterRegistry;
use App\Domain\Finance\FinancialProviderRefundRequest;
use App\Domain\Inventory\InventoryMovementConfirmer;
use App\Domain\Inventory\InventoryMovementCreator;
use App\Domain\Inventory\InventoryMovementDraftData;
use App\Domain\Inventory\InventoryMovementLineData;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CommercePaymentMethod;
use App\Enums\CommercePostSaleIntent;
use App\Enums\CommercePostSaleResolutionOutcome;
use App\Enums\FinancialAccountType;
use App\Enums\FinancialMovementDirection;
use App\Enums\FinancialMovementSource;
use App\Enums\FinancialMovementStatus;
use App\Enums\FinancialProviderCapability;
use App\Enums\FinancialProviderCompatibilityStatus;
use App\Enums\FinancialProviderConnectionHealthStatus;
use App\Enums\InventoryCondition;
use App\Enums\InventoryMovementType;
use App\Enums\UserRole;
use App\Models\BusinessParty;
use App\Models\CatalogProduct;
use App\Models\CommercePostSaleExternalRefundDispatch;
use App\Models\CommercePostSaleExternalRefundEvidence;
use App\Models\FinancialProviderConnection;
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
use RuntimeException;
use Tests\TestCase;

final class SyntheticP8432RefundAdapter
    implements FinancialProviderRefundAdapter
{
    public int $calls = 0;

    public bool $throwOnce = false;

    public FinancialMovementStatus $status =
        FinancialMovementStatus::Posted;

    public FinancialMovementDirection $direction =
        FinancialMovementDirection::Debit;

    public ?int $grossAmountMinor = null;

    public ?FinancialProviderRefundRequest $lastRequest = null;

    public function providerKey(): string
    {
        return 'test-refund-submit';
    }

    public function submitRefund(
        FinancialProviderConnection $connection,
        FinancialProviderRefundRequest $request
    ): ExternalFinancialProviderObservation {
        $this->calls++;
        $this->lastRequest = $request;

        if ($this->throwOnce) {
            $this->throwOnce = false;

            throw new RuntimeException(
                'synthetic_unknown_network_outcome'
            );
        }

        $amount =
            $this->grossAmountMinor
            ?? $request->amountMinor;

        return new ExternalFinancialProviderObservation(
            providerKey:
                $connection->provider_key,
            observationKey:
                'refund:'
                .$request->instructionPublicId
                .':'
                .$this->status->value,
            externalOperationId:
                'RF-'
                .$request->instructionPublicId,
            direction:
                $this->direction,
            status:
                $this->status,
            currencyCode:
                $request->currencyCode,
            grossAmountMinor:
                $amount,
            netAmountMinor:
                $amount,
            feeAmountMinor:
                0,
            withholdingAmountMinor:
                0,
            rawReference:
                'synthetic-refund-safe-ref',
            occurredAt:
                CarbonImmutable::now('UTC')
        );
    }
}

class CommercePostSaleExternalRefundSubmissionEvidenceFoundationTest
    extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_schema_contract_and_instruction_relation_are_explicit(): void
    {
        $this->assertTrue(
            Schema::hasColumns(
                'commerce_post_sale_external_refund_dispatches',
                [
                    'organization_id',
                    'public_id',
                    'commerce_post_sale_external_refund_instruction_id',
                    'financial_provider_connection_id',
                    'financial_account_id',
                    'provider_key',
                    'provider_idempotency_key',
                    'fingerprint',
                    'created_at',
                ]
            )
        );

        $this->assertTrue(
            Schema::hasColumns(
                'commerce_post_sale_external_refund_evidence',
                [
                    'organization_id',
                    'public_id',
                    'commerce_post_sale_external_refund_dispatch_id',
                    'financial_external_movement_id',
                    'source',
                    'fingerprint',
                    'observed_at',
                    'created_at',
                ]
            )
        );

        $registry =
            new FinancialProviderRefundAdapterRegistry();

        $this->assertDomainFailure(
            fn () => $registry->adapterFor(
                'mercado-pago'
            )
        );
    }

    public function test_posted_submission_uses_stable_provider_idempotency_and_same_external_ledger(): void
    {
        $context =
            $this->context(
                'posted'
            );

        $adapter =
            new SyntheticP8432RefundAdapter();

        $manager =
            $this->submissionManager(
                $adapter
            );

        $paymentBefore =
            DB::table('commerce_payments')
                ->where(
                    'id',
                    $context[
                        'externalPayment'
                    ]->id
                )
                ->first();

        $cashBefore =
            DB::table('cash_movements')
                ->count();

        $evidence =
            $manager->submit(
                $context['instruction']
            );

        $dispatch =
            $evidence->dispatch;

        $movement =
            $evidence->financialMovement;

        $this->assertNotNull($dispatch);
        $this->assertNotNull($movement);

        $this->assertSame(
            1,
            $adapter->calls
        );

        $this->assertSame(
            'srcm-refund:'
                .$context['instruction']
                    ->public_id,
            $dispatch
                ->provider_idempotency_key
        );

        $this->assertSame(
            $dispatch
                ->provider_idempotency_key,
            $adapter
                ->lastRequest
                ?->providerIdempotencyKey
        );

        $this->assertSame(
            $context['externalPayment']
                ->external_operation_id,
            $adapter
                ->lastRequest
                ?->originalExternalOperationId
        );

        $this->assertSame(
            FinancialMovementDirection::Debit,
            $movement->direction
        );

        $this->assertSame(
            FinancialMovementStatus::Posted,
            $movement->status
        );

        $this->assertSame(
            FinancialMovementSource::Api,
            $movement->source
        );

        $this->assertSame(
            10000,
            $movement->gross_amount_minor
        );

        $this->assertSame(
            $context['externalAccount']->id,
            $movement->financial_account_id
        );

        $this->assertEquals(
            $paymentBefore,
            DB::table('commerce_payments')
                ->where(
                    'id',
                    $context[
                        'externalPayment'
                    ]->id
                )
                ->first()
        );

        $this->assertSame(
            $cashBefore,
            DB::table('cash_movements')
                ->count()
        );

        $this->assertDatabaseCount(
            'commerce_post_sale_external_refund_dispatches',
            1
        );

        $this->assertDatabaseCount(
            'commerce_post_sale_external_refund_evidence',
            1
        );

        $this->assertDatabaseCount(
            'financial_external_movements',
            1
        );

        $this->assertDatabaseHas(
            'audit_logs',
            [
                'event' =>
                    'commerce_post_sale_external_refund_dispatch_prepared',
            ]
        );

        $this->assertDatabaseHas(
            'audit_logs',
            [
                'event' =>
                    'commerce_post_sale_external_refund_evidence_recorded',
            ]
        );
    }

    public function test_pending_then_polling_posted_appends_evidence_without_resubmitting(): void
    {
        $context =
            $this->context(
                'progression'
            );

        $adapter =
            new SyntheticP8432RefundAdapter();

        $adapter->status =
            FinancialMovementStatus::Pending;

        $manager =
            $this->submissionManager(
                $adapter
            );

        $pending =
            $manager->submit(
                $context['instruction']
            );

        $this->assertSame(
            FinancialMovementStatus::Pending,
            $pending
                ->financialMovement
                ->status
        );

        $postedObservation =
            new ExternalFinancialProviderObservation(
                providerKey:
                    'test-refund-submit',
                observationKey:
                    'refund:'
                    .$context['instruction']
                        ->public_id
                    .':posted',
                externalOperationId:
                    'RF-'
                    .$context['instruction']
                        ->public_id,
                direction:
                    FinancialMovementDirection::Debit,
                status:
                    FinancialMovementStatus::Posted,
                currencyCode:
                    'ARS',
                grossAmountMinor:
                    10000,
                netAmountMinor:
                    10000,
                occurredAt:
                    CarbonImmutable::now(
                        'UTC'
                    )
            );

        $posted =
            $manager->recordObservation(
                $pending->dispatch,
                FinancialMovementSource::Polling,
                $postedObservation
            );

        $this->assertSame(
            FinancialMovementStatus::Posted,
            $posted
                ->financialMovement
                ->status
        );

        $this->assertDatabaseCount(
            'commerce_post_sale_external_refund_dispatches',
            1
        );

        $this->assertDatabaseCount(
            'commerce_post_sale_external_refund_evidence',
            2
        );

        $this->assertDatabaseCount(
            'financial_external_movements',
            2
        );

        app(
            FinancialProviderConnectionHealthManager::class
        )->record(
            $context['connection'],
            new FinancialProviderHealthObservation(
                capability:
                    FinancialProviderCapability::Refund,
                status:
                    FinancialProviderConnectionHealthStatus::Unavailable,
                checkedAt:
                    CarbonImmutable::now()
                        ->addSecond(),
                sourceKey:
                    'test:refund-submit:unavailable:'
                    .Str::lower(
                        Str::random(6)
                    )
            )
        );

        $again =
            $manager->submit(
                $context['instruction']
            );

        $this->assertSame(
            1,
            $adapter->calls
        );

        $this->assertSame(
            $pending->id,
            $again->id
        );
    }

    public function test_unknown_network_outcome_leaves_durable_dispatch_and_recovers_with_same_key(): void
    {
        $context =
            $this->context(
                'recovery'
            );

        $adapter =
            new SyntheticP8432RefundAdapter();

        $adapter->throwOnce =
            true;

        $manager =
            $this->submissionManager(
                $adapter
            );

        try {
            $manager->submit(
                $context['instruction']
            );

            $this->fail(
                'Se esperaba resultado externo incierto.'
            );
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'synthetic_unknown_network_outcome',
                $exception->getMessage()
            );
        }

        $dispatch =
            CommercePostSaleExternalRefundDispatch::query()
                ->sole();

        $this->assertSame(
            'srcm-refund:'
                .$context['instruction']
                    ->public_id,
            $dispatch
                ->provider_idempotency_key
        );

        $this->assertDatabaseCount(
            'commerce_post_sale_external_refund_evidence',
            0
        );

        $this->assertDatabaseCount(
            'financial_external_movements',
            0
        );

        $recovered =
            $manager->submit(
                $context['instruction']
            );

        $this->assertSame(
            $dispatch->id,
            $recovered
                ->dispatch
                ->id
        );

        $this->assertSame(
            2,
            $adapter->calls
        );

        $this->assertSame(
            'srcm-refund:'
                .$context['instruction']
                    ->public_id,
            $adapter
                ->lastRequest
                ?->providerIdempotencyKey
        );

        $this->assertDatabaseCount(
            'commerce_post_sale_external_refund_dispatches',
            1
        );

        $this->assertDatabaseCount(
            'commerce_post_sale_external_refund_evidence',
            1
        );
    }

    public function test_wrong_provider_financial_shape_fails_before_external_ledger_truth(): void
    {
        $context =
            $this->context(
                'bad-shape'
            );

        $adapter =
            new SyntheticP8432RefundAdapter();

        $adapter->direction =
            FinancialMovementDirection::Credit;

        $manager =
            $this->submissionManager(
                $adapter
            );

        $this->assertDomainFailure(
            fn () => $manager->submit(
                $context['instruction']
            )
        );

        $this->assertDatabaseCount(
            'commerce_post_sale_external_refund_dispatches',
            1
        );

        $this->assertDatabaseCount(
            'commerce_post_sale_external_refund_evidence',
            0
        );

        $this->assertDatabaseCount(
            'financial_external_movements',
            0
        );
    }

    public function test_dispatch_and_evidence_are_append_only_and_database_guards_block_forgery(): void
    {
        $context =
            $this->context(
                'guards'
            );

        $adapter =
            new SyntheticP8432RefundAdapter();

        $manager =
            $this->submissionManager(
                $adapter
            );

        $this->assertQueryRejected(
            fn () => DB::table(
                'commerce_post_sale_external_refund_dispatches'
            )->insert([
                'organization_id' =>
                    $context['organization']->id,
                'public_id' =>
                    (string) Str::uuid(),
                'commerce_post_sale_external_refund_instruction_id' =>
                    $context['instruction']->id,
                'financial_provider_connection_id' =>
                    $context['connection']->id,
                'financial_account_id' =>
                    $context['externalAccount']->id,
                'provider_key' =>
                    'test-refund-submit',
                'provider_idempotency_key' =>
                    'forged-new-key',
                'fingerprint' =>
                    str_repeat('a', 64),
                'created_at' =>
                    now(),
            ])
        );

        $evidence =
            $manager->submit(
                $context['instruction']
            );

        $dispatch =
            $evidence->dispatch;

        $this->assertQueryRejected(
            fn () => DB::table(
                'commerce_post_sale_external_refund_dispatches'
            )
                ->where(
                    'id',
                    $dispatch->id
                )
                ->update([
                    'provider_key' =>
                        'tampered',
                ])
        );

        $this->assertQueryRejected(
            fn () => DB::table(
                'commerce_post_sale_external_refund_evidence'
            )
                ->where(
                    'id',
                    $evidence->id
                )
                ->delete()
        );

        $creditMovement =
            app(
                ExternalFinancialProviderIngestor::class
            )->ingest(
                $context['connection'],
                FinancialMovementSource::Api,
                new ExternalFinancialProviderObservation(
                    providerKey:
                        'test-refund-submit',
                    observationKey:
                        'unrelated-credit:'
                        .$context['instruction']
                            ->public_id,
                    externalOperationId:
                        'UNRELATED-'
                        .$context['instruction']
                            ->public_id,
                    direction:
                        FinancialMovementDirection::Credit,
                    status:
                        FinancialMovementStatus::Posted,
                    currencyCode:
                        'ARS',
                    grossAmountMinor:
                        10000,
                    netAmountMinor:
                        10000,
                    occurredAt:
                        CarbonImmutable::now(
                            'UTC'
                        )
                )
            );

        $this->assertQueryRejected(
            fn () => DB::table(
                'commerce_post_sale_external_refund_evidence'
            )->insert([
                'organization_id' =>
                    $context['organization']->id,
                'public_id' =>
                    (string) Str::uuid(),
                'commerce_post_sale_external_refund_dispatch_id' =>
                    $dispatch->id,
                'financial_external_movement_id' =>
                    $creditMovement->id,
                'source' =>
                    'api',
                'fingerprint' =>
                    str_repeat('a', 64),
                'observed_at' =>
                    now(),
                'created_at' =>
                    now(),
            ])
        );
    }

    public function test_missing_adapter_fails_closed_without_creating_dispatch(): void
    {
        $context =
            $this->context(
                'missing-adapter'
            );

        $manager =
            new CommercePostSaleExternalRefundSubmissionManager(
                new FinancialProviderRefundAdapterRegistry(),
                app(
                    FinancialProviderAutomationGate::class
                ),
                app(
                    ExternalFinancialProviderIngestor::class
                ),
                app(
                    AuditRecorder::class
                )
            );

        $this->assertDomainFailure(
            fn () => $manager->submit(
                $context['instruction']
            )
        );

        $this->assertDatabaseCount(
            'commerce_post_sale_external_refund_dispatches',
            0
        );

        $this->assertDatabaseCount(
            'financial_external_movements',
            0
        );
    }

    private function submissionManager(
        FinancialProviderRefundAdapter $adapter
    ): CommercePostSaleExternalRefundSubmissionManager {
        return new CommercePostSaleExternalRefundSubmissionManager(
            new FinancialProviderRefundAdapterRegistry([
                $adapter,
            ]),
            app(
                FinancialProviderAutomationGate::class
            ),
            app(
                ExternalFinancialProviderIngestor::class
            ),
            app(
                AuditRecorder::class
            )
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
                        'Cliente P8.4.3.2 '
                        .$suffix,
                ]);

        $category =
            ProductCategory::withoutEvents(
                fn () =>
                    ProductCategory::query()
                        ->firstOrCreate(
                            [
                                'slug' =>
                                    'post-sale-external-refund-submission-tests',
                            ],
                            [
                                'name' =>
                                    'Ejecución externa posventa',
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
                                'P8432-'
                                .Str::upper(
                                    Str::random(8)
                                ),
                            'name' =>
                                'Producto P8.4.3.2 '
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
            'Precio P8.4.3.2.',
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
                        'Stock previo P8.4.3.2.',
                    idempotencyKey:
                        'p8432:stock:'
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

        $externalAccount =
            app(
                FinancialAccountManager::class
            )->create(
                'Refund Submit '
                .$suffix,
                FinancialAccountType::DigitalWallet,
                'ARS',
                $admin,
                'Test Refund Submit',
                'P8.4.3.2 synthetic'
            );

        $connection =
            app(
                FinancialProviderConnectionManager::class
            )->connect(
                $externalAccount,
                'test-refund-submit',
                $admin,
                'acct-'.$suffix
            );

        $compatibility =
            app(
                FinancialProviderCompatibilityRegistry::class
            )->register(
                registryKey:
                    'test-refund-submit:refund-v1:'
                    .Str::lower(
                        Str::random(8)
                    ),
                providerKey:
                    'test-refund-submit',
                providerLabel:
                    'Test Refund Submit',
                providerContractVersion:
                    'refund-v1',
                providerContractReference:
                    'Contrato sintético P8.4.3.2.',
                adapterClass:
                    SyntheticP8432RefundAdapter::class,
                adapterContractVersion:
                    'refund-submission-v1',
                status:
                    FinancialProviderCompatibilityStatus::Compatible,
                migrationRequired:
                    false,
                srcmVersion:
                    '0e2edcd7284474a08afd7fc0552a11105f65180e',
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
                            'P8.4.3.2 synthetic refund submission.',
                        'notes' =>
                            'Sin HTTP real.',
                    ],
                ],
                notes:
                    'Fixture local P8.4.3.2.'
            );

        app(
            FinancialProviderConnectionCompatibilityManager::class
        )->bind(
            $connection,
            $compatibility,
            $admin
        );

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
                    'test:refund-submit:healthy:'
                    .Str::lower(
                        Str::random(6)
                    )
            )
        );

        $sale =
            app(
                CommerceCheckoutManager::class
            )->checkout(
                new CommerceCheckoutData(
                    currencyCode:
                        'ARS',
                    idempotencyKey:
                        'p8432:sale:'
                        .$suffix.':'
                        .$operator->id,
                    payments: [
                        new CommercePaymentData(
                            method:
                                CommercePaymentMethod::DigitalWallet,
                            amountMinor:
                                20000,
                            reference:
                                'P8432-'
                                .$suffix,
                            processor:
                                'Test Refund Submit',
                            externalOperationId:
                                'PAY-P8432-'
                                .$suffix,
                            providerStatus:
                                'approved',
                            financialAccountId:
                                $externalAccount->id
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
            $sale->payments->sole();

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
                                '1'
                        ),
                    ],
                    reason:
                        'El cliente solicita devolución con reembolso al medio externo original.',
                    idempotencyKey:
                        'p8432:request:'
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
                                '1',
                            condition:
                                InventoryCondition::Used,
                            destinationLocationId:
                                $location->id
                        ),
                    ],
                    idempotencyKey:
                        'p8432:receipt:'
                        .$suffix.':'
                        .$operator->id
                ),
                $operator
            );

        $resolution =
            app(
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
                                $receipt
                                    ->lines
                                    ->sole()
                                    ->id,
                            quantity:
                                '1',
                            recognizedAmountMinor:
                                10000
                        ),
                    ],
                    reason:
                        'Se reconoce el valor para reintegrar al medio externo original.',
                    idempotencyKey:
                        'p8432:resolution:'
                        .$suffix,
                    preferredOriginalPaymentId:
                        $externalPayment->id
                ),
                $admin
            );

        $instruction =
            app(
                CommercePostSaleExternalRefundInstructionManager::class
            )->request(
                $resolution,
                'p8432:instruction:'
                    .$suffix,
                $operator
            );

        return [
            'organization' =>
                $organization,
            'admin' =>
                $admin,
            'operator' =>
                $operator,
            'externalAccount' =>
                $externalAccount,
            'connection' =>
                $connection,
            'sale' =>
                $sale,
            'externalPayment' =>
                $externalPayment,
            'request' =>
                $request,
            'resolution' =>
                $resolution,
            'instruction' =>
                $instruction,
        ];
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
