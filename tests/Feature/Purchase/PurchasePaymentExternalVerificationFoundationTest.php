<?php

namespace Tests\Feature\Purchase;

use App\Domain\Finance\CashRegisterManager;
use App\Domain\Finance\CashRegisterSessionManager;
use App\Domain\Finance\ExternalFinancialMovementData;
use App\Domain\Finance\ExternalFinancialMovementRecorder;
use App\Domain\Finance\FinancialAccountManager;
use App\Domain\Purchase\PurchaseObligationData;
use App\Domain\Purchase\PurchaseObligationManager;
use App\Domain\Purchase\PurchaseOrderDraftData;
use App\Domain\Purchase\PurchaseOrderLineData;
use App\Domain\Purchase\PurchaseOrderManager;
use App\Domain\Purchase\PurchasePaymentControlReader;
use App\Domain\Purchase\PurchasePaymentDisbursementManager;
use App\Domain\Purchase\PurchasePaymentExternalVerificationManager;
use App\Domain\Purchase\PurchasePaymentExternalVerificationReader;
use App\Domain\Purchase\PurchasePaymentRequestData;
use App\Domain\Purchase\PurchasePaymentRequestManager;
use App\Domain\Purchase\PurchaseReceiptData;
use App\Domain\Purchase\PurchaseReceiptLineData;
use App\Domain\Purchase\PurchaseReceiptManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\FinancialAccountType;
use App\Enums\FinancialMovementDirection;
use App\Enums\FinancialMovementSource;
use App\Enums\FinancialMovementStatus;
use App\Enums\InventoryCondition;
use App\Enums\InventoryLocationType;
use App\Enums\PurchaseObligationCondition;
use App\Enums\PurchaseObligationKind;
use App\Enums\UserRole;
use App\Models\BusinessParty;
use App\Models\CatalogProduct;
use App\Models\FinancialExternalMovement;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductCategory;
use App\Models\PurchasePaymentDisbursement;
use App\Models\PurchasePaymentExternalVerification;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchasePaymentExternalVerificationFoundationTest
    extends TestCase
{
    use RefreshDatabase;

    public function test_schema_route_and_append_only_contract_are_explicit(): void
    {
        $this->assertTrue(
            Schema::hasColumns(
                'purchase_payment_external_verifications',
                [
                    'organization_id',
                    'public_id',
                    'purchase_payment_disbursement_id',
                    'financial_external_movement_id',
                    'idempotency_key',
                    'fingerprint',
                    'reference_match_kind',
                    'amount_difference_minor',
                    'note',
                    'verified_by_user_id',
                    'verified_at',
                    'created_at',
                ]
            )
        );

        $this->assertTrue(
            Route::has(
                'purchase-payment-disbursements.external-verifications.store'
            )
        );

        $migration = file_get_contents(
            database_path(
                'migrations/2026_08_17_073000_create_purchase_payment_external_verification_foundation.php'
            )
        );

        $this->assertIsString($migration);
        $this->assertStringContainsString(
            'post_sale_refund_no_purchase_payment_insert',
            $migration
        );
        $this->assertStringContainsString(
            'pay_reconciliation_no_purchase_payment_insert',
            $migration
        );
    }

    public function test_exact_posted_debit_verifies_idempotently_without_parallel_ledgers(): void
    {
        $context = $this->executedContext(
            'exact',
            FinancialAccountType::BankAccount,
            900,
            'TRF-P97K-EXACT'
        );
        $movement = $this->movement(
            $context,
            'exact',
            900,
            900,
            externalOperationId:
                'TRF-P97K-EXACT'
        );

        $this->actingAs($context['admin']);
        $manager = app(
            PurchasePaymentExternalVerificationManager::class
        );

        $verification = $manager->verify(
            $context['disbursement'],
            $movement,
            'p97k:verify:exact',
            $context['admin']
        );
        $retry = $manager->verify(
            $context['disbursement']->refresh(),
            $movement->refresh(),
            'p97k:verify:exact',
            $context['admin']
        );

        $this->assertSame($verification->id, $retry->id);
        $this->assertSame(
            'external_operation_id',
            $verification->reference_match_kind
        );
        $this->assertSame(
            0,
            $verification->amount_difference_minor
        );
        $this->assertDatabaseCount(
            'purchase_payment_external_verifications',
            1
        );
        $this->assertDatabaseCount(
            'payment_reconciliations',
            0
        );
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertSame(
            'external_debit_verified',
            app(PurchasePaymentControlReader::class)
                ->readDisbursement(
                    $context['disbursement']->refresh(),
                    $context['admin']
                )['state']
        );
        $this->assertDatabaseHas('audit_logs', [
            'event' =>
                'purchase_payment_external_verified',
        ]);
    }

    public function test_amount_charges_or_reference_difference_requires_reason_and_stays_explicit(): void
    {
        $context = $this->executedContext(
            'difference',
            FinancialAccountType::BankAccount,
            900,
            'TRF-P97K-DIFFERENCE'
        );
        $movement = $this->movement(
            $context,
            'difference',
            1000,
            950,
            feeMinor: 50,
            externalOperationId:
                'TRF-P97K-DIFFERENCE'
        );
        $manager = app(
            PurchasePaymentExternalVerificationManager::class
        );

        $this->assertDomainFailure(
            fn () => $manager->verify(
                $context['disbursement'],
                $movement,
                'p97k:verify:difference:no-note',
                $context['admin']
            )
        );

        $verification = $manager->verify(
            $context['disbursement'],
            $movement,
            'p97k:verify:difference',
            $context['admin'],
            'La entidad debitó un cargo adicional explícito.'
        );

        $this->assertSame(
            100,
            $verification->amount_difference_minor
        );
        $control = app(
            PurchasePaymentControlReader::class
        )->readDisbursement(
            $context['disbursement']->refresh(),
            $context['admin']
        );

        $this->assertSame(
            'external_verification_difference',
            $control['state']
        );
        $this->assertSame(100, $control['difference_minor']);
        $this->assertStringContainsString(
            'No fue compensada ni resuelta automáticamente',
            $control['detail']
        );
    }

    public function test_operator_confirmation_without_exact_reference_requires_reason(): void
    {
        $context = $this->executedContext(
            'reference',
            FinancialAccountType::DigitalWallet,
            700,
            'DECLARED-REFERENCE'
        );
        $movement = $this->movement(
            $context,
            'reference',
            700,
            700,
            externalOperationId:
                'EXTERNAL-OTHER-REFERENCE',
            rawReference:
                'Otra referencia externa'
        );
        $manager = app(
            PurchasePaymentExternalVerificationManager::class
        );

        $this->assertDomainFailure(
            fn () => $manager->verify(
                $context['disbursement'],
                $movement,
                'p97k:verify:reference:no-note',
                $context['admin']
            )
        );

        $verification = $manager->verify(
            $context['disbursement'],
            $movement,
            'p97k:verify:reference',
            $context['admin'],
            'Confirmación manual contra comprobante bancario.'
        );

        $this->assertSame(
            'operator_confirmed',
            $verification->reference_match_kind
        );
        $this->assertSame(
            'external_debit_verified',
            app(PurchasePaymentControlReader::class)
                ->readDisbursement(
                    $context['disbursement']->refresh(),
                    $context['admin']
                )['state']
        );
    }

    public function test_cash_credit_pending_foreign_and_non_admin_paths_fail_closed(): void
    {
        $context = $this->executedContext(
            'guards',
            FinancialAccountType::BankAccount,
            800,
            'TRF-P97K-GUARDS'
        );
        $credit = $this->movement(
            $context,
            'credit',
            800,
            800,
            direction:
                FinancialMovementDirection::Credit,
            externalOperationId:
                'TRF-P97K-GUARDS'
        );
        $pending = $this->movement(
            $context,
            'pending',
            800,
            800,
            status:
                FinancialMovementStatus::Pending,
            externalOperationId:
                'TRF-P97K-GUARDS'
        );
        $manager = app(
            PurchasePaymentExternalVerificationManager::class
        );

        $this->assertDomainFailure(
            fn () => $manager->verify(
                $context['disbursement'],
                $credit,
                'p97k:guards:credit',
                $context['admin']
            )
        );
        $this->assertDomainFailure(
            fn () => $manager->verify(
                $context['disbursement'],
                $pending,
                'p97k:guards:pending',
                $context['admin']
            )
        );
        $this->assertDomainFailure(
            fn () => $manager->verify(
                $context['disbursement'],
                $pending,
                'p97k:guards:operator',
                $context['operator']
            )
        );

        $cash = $this->executedContext(
            'cash',
            FinancialAccountType::CashBox,
            500,
            null,
            2000
        );

        $this->assertDomainFailure(
            fn () => $manager->verify(
                $cash['disbursement'],
                $credit,
                'p97k:guards:cash',
                $cash['admin']
            )
        );

        $foreign = $this->executedContext(
            'foreign',
            FinancialAccountType::BankAccount,
            800,
            'TRF-P97K-FOREIGN'
        );
        $foreignMovement = $this->movement(
            $foreign,
            'foreign',
            800,
            800,
            externalOperationId:
                'TRF-P97K-FOREIGN'
        );

        $this->assertDomainFailure(
            fn () => $manager->verify(
                $context['disbursement'],
                $foreignMovement,
                'p97k:guards:foreign',
                $context['admin']
            )
        );
        $this->assertDatabaseCount(
            'purchase_payment_external_verifications',
            0
        );
    }

    public function test_admin_http_sees_ranked_candidates_and_operator_cannot_verify(): void
    {
        $context = $this->executedContext(
            'http',
            FinancialAccountType::BankAccount,
            1100,
            'DECLARED-P97K-HTTP'
        );
        $movement = $this->movement(
            $context,
            'http',
            1100,
            1100,
            externalOperationId:
                'EXTERNAL-P97K-HTTP',
            rawReference:
                'DECLARED-P97K-HTTP'
        );

        $candidates = app(
            PurchasePaymentExternalVerificationReader::class
        )->candidates(
            $context['disbursement'],
            $context['admin']
        );

        $this->assertCount(1, $candidates);
        $this->assertSame(
            'raw_reference',
            $candidates[0]['reference_match_kind']
        );
        $this->assertFalse(
            $candidates[0]['note_required']
        );

        $this->actingAs($context['admin'])
            ->get(route('purchase-payment-operations.index'))
            ->assertOk()
            ->assertSee('EXTERNAL-P97K-HTTP')
            ->assertSee('Candidatos de débito externo');

        $this->actingAs($context['operator'])
            ->post(
                route(
                    'purchase-payment-disbursements.external-verifications.store',
                    [
                        $context['disbursement'],
                        $movement->public_id,
                    ]
                ),
                [
                    'confirm_verify' => '1',
                    'idempotency_key' =>
                        'purchase-ui:payment-external-verify:'.
                        Str::uuid(),
                ]
            )
            ->assertForbidden();

        $this->actingAs($context['admin'])
            ->post(
                route(
                    'purchase-payment-disbursements.external-verifications.store',
                    [
                        $context['disbursement'],
                        $movement->public_id,
                    ]
                ),
                [
                    'confirm_verify' => '1',
                    'idempotency_key' =>
                        'purchase-ui:payment-external-verify:'.
                        Str::uuid(),
                ]
            )
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->get(route('purchase-payment-operations.index'))
            ->assertOk()
            ->assertSee('Débito externo verificado')
            ->assertSee('Evidencia financiera externa append-only');
    }

    public function test_verification_is_immutable_database_guarded_and_later_reversal_is_visible(): void
    {
        $context = $this->executedContext(
            'immutable',
            FinancialAccountType::BankAccount,
            600,
            'TRF-P97K-IMMUTABLE'
        );
        $movement = $this->movement(
            $context,
            'immutable-posted',
            600,
            600,
            externalOperationId:
                'TRF-P97K-IMMUTABLE',
            occurredAt:
                CarbonImmutable::instance(
                    $context['disbursement']->executed_at
                )->addMinute()
        );
        $verification = app(
            PurchasePaymentExternalVerificationManager::class
        )->verify(
            $context['disbursement'],
            $movement,
            'p97k:verify:immutable',
            $context['admin']
        );

        $this->assertDomainFailure(function () use (
            $verification
        ): void {
            $verification->note = 'Mutación prohibida';
            $verification->save();
        });
        $this->assertDomainFailure(
            fn () => $verification->delete()
        );
        $this->assertQueryRejected(
            fn () => DB::table(
                'purchase_payment_external_verifications'
            )
                ->where('id', $verification->id)
                ->update(['note' => 'forged'])
        );
        $this->assertQueryRejected(
            fn () => DB::table(
                'purchase_payment_external_verifications'
            )
                ->where('id', $verification->id)
                ->delete()
        );

        $this->movement(
            $context,
            'immutable-reversed',
            600,
            600,
            status:
                FinancialMovementStatus::Reversed,
            externalOperationId:
                'TRF-P97K-IMMUTABLE',
            occurredAt:
                CarbonImmutable::instance(
                    $context['disbursement']->executed_at
                )->addMinutes(2)
        );

        $control = app(
            PurchasePaymentControlReader::class
        )->readDisbursement(
            $context['disbursement']->refresh(),
            $context['admin']
        );

        $this->assertSame(
            'external_debit_reversed',
            $control['state']
        );
        $this->assertStringContainsString(
            'no vuelve a pagar',
            $control['detail']
        );
        $this->assertDatabaseCount(
            'purchase_payment_external_verifications',
            1
        );
    }

    private function executedContext(
        string $suffix,
        FinancialAccountType $type,
        int $amountMinor,
        ?string $reference,
        int $openingMinor = 0
    ): array {
        $base = $this->baseContext(
            $suffix,
            $type,
            $openingMinor
        );
        $purchase = $this->purchase(
            $base,
            $suffix,
            $amountMinor
        );

        $this->actingAs($base['operator']);
        $paymentRequest = app(
            PurchasePaymentRequestManager::class
        )->request(
            new PurchasePaymentRequestData(
                purchaseObligationId:
                    $purchase['obligation']->id,
                originFinancialAccountId:
                    $base['account']->id,
                amountMinor: $amountMinor,
                requestNote: 'Solicitud P9.7k',
                idempotencyKey:
                    'p97k:request:'.$suffix
            ),
            $base['operator']
        );

        $this->actingAs($base['admin']);
        $paymentRequest = app(
            PurchasePaymentRequestManager::class
        )->approve(
            $paymentRequest,
            'Aprobación P9.7k',
            'p97k:approve:'.$suffix,
            $base['admin']
        );

        $this->actingAs($base['operator']);
        $disbursement = app(
            PurchasePaymentDisbursementManager::class
        )->executeIndividual(
            $paymentRequest,
            $reference,
            'Desembolso P9.7k',
            'p97k:execute:'.$suffix,
            $base['operator']
        );

        return array_merge(
            $base,
            $purchase,
            compact(
                'paymentRequest',
                'disbursement'
            )
        );
    }

    private function movement(
        array $context,
        string $suffix,
        int $grossMinor,
        int $netMinor,
        int $feeMinor = 0,
        int $withholdingMinor = 0,
        FinancialMovementDirection $direction =
            FinancialMovementDirection::Debit,
        FinancialMovementStatus $status =
            FinancialMovementStatus::Posted,
        ?string $externalOperationId = null,
        ?string $rawReference = null,
        ?CarbonImmutable $occurredAt = null
    ): FinancialExternalMovement {
        $this->actingAs($context['admin']);

        return app(
            ExternalFinancialMovementRecorder::class
        )->record(
            $context['account'],
            new ExternalFinancialMovementData(
                source: FinancialMovementSource::Manual,
                sourceKey:
                    'p97k:movement:'.$suffix.':'.Str::uuid(),
                direction: $direction,
                status: $status,
                currencyCode: 'ARS',
                grossAmountMinor: $grossMinor,
                netAmountMinor: $netMinor,
                feeAmountMinor: $feeMinor,
                withholdingAmountMinor:
                    $withholdingMinor,
                externalOperationId:
                    $externalOperationId,
                rawReference: $rawReference,
                occurredAt: $occurredAt
                    ?? CarbonImmutable::instance(
                        $context['disbursement']
                            ->executed_at
                    )
            ),
            $context['admin']
        );
    }

    private function baseContext(
        string $suffix,
        FinancialAccountType $type,
        int $openingMinor = 0
    ): array {
        $organization = Organization::query()->create([
            'name' => 'Org P9.7k '.$suffix,
            'slug' => 'org-p97k-'.$suffix.'-'.
                Str::lower(Str::random(6)),
            'active' => true,
        ]);
        $admin = $this->member(
            $organization,
            UserRole::Admin
        );
        $operator = $this->member(
            $organization,
            UserRole::Operator
        );
        $party = BusinessParty::query()->create([
            'organization_id' => $organization->id,
            'party_type' =>
                BusinessParty::TYPE_ORGANIZATION,
            'name' => 'Proveedor P9.7k '.$suffix,
        ]);
        $supplier = Supplier::query()->create([
            'organization_id' => $organization->id,
            'business_party_id' => $party->id,
            'active' => true,
        ]);

        $this->actingAs($admin);
        $account = app(
            FinancialAccountManager::class
        )->create(
            'Cuenta P9.7k '.$suffix,
            $type,
            'ARS',
            $admin
        );
        $register = null;
        $session = null;

        if ($type === FinancialAccountType::CashBox) {
            $register = app(
                CashRegisterManager::class
            )->create(
                'Caja P9.7k '.$suffix,
                $account,
                $admin
            );
            $this->actingAs($operator);
            $session = app(
                CashRegisterSessionManager::class
            )->open(
                $register,
                $openingMinor,
                'p97k:session:'.$suffix,
                $operator
            );
        }

        return compact(
            'organization',
            'admin',
            'operator',
            'party',
            'supplier',
            'account',
            'register',
            'session'
        );
    }

    private function purchase(
        array $context,
        string $suffix,
        int $amountMinor
    ): array {
        $category = ProductCategory::query()
            ->firstOrCreate(
                ['slug' => 'p97k-tests'],
                [
                    'name' => 'Pruebas P9.7k',
                    'active' => true,
                ]
            );
        $product = CatalogProduct::query()->create([
            'product_category_id' => $category->id,
            'sku' => 'P97K-'.
                Str::upper(Str::random(10)),
            'name' => 'Producto P9.7k '.$suffix,
            'base_unit_code' => 'unit',
            'quantity_scale' => 0,
            'active' => true,
        ]);
        $location = InventoryLocation::query()->create([
            'organization_id' =>
                $context['organization']->id,
            'name' => 'Recepción P9.7k '.Str::uuid(),
            'type' => InventoryLocationType::Receiving,
            'active' => true,
        ]);

        $this->actingAs($context['operator']);
        $orders = app(PurchaseOrderManager::class);
        $order = $orders->draft(
            new PurchaseOrderDraftData(
                supplierId:
                    $context['supplier']->id,
                currencyCode: 'ARS',
                idempotencyKey:
                    'p97k:order:'.$suffix.':'.Str::uuid(),
                lines: [
                    new PurchaseOrderLineData(
                        $product->id,
                        '1',
                        $amountMinor
                    ),
                ]
            ),
            $context['operator']
        );
        $order = $orders->issue(
            $order,
            $context['operator']
        );
        $order->load('lines');
        $receipt = app(
            PurchaseReceiptManager::class
        )->receive(
            new PurchaseReceiptData(
                purchaseOrderId: $order->id,
                receivedAt: CarbonImmutable::parse(
                    '2026-08-17 18:00:00',
                    'America/Argentina/Buenos_Aires'
                ),
                idempotencyKey:
                    'p97k:receipt:'.$suffix.':'.Str::uuid(),
                lines: [
                    new PurchaseReceiptLineData(
                        purchaseOrderLineId:
                            $order->lines->first()->id,
                        quantity: '1',
                        inventoryLocationId:
                            $location->id,
                        condition:
                            InventoryCondition::New,
                        actualUnitCostMinor:
                            $amountMinor
                    ),
                ],
                logisticsCostMinor: 0,
                documentReference:
                    'P97K-'.$suffix
            ),
            $context['operator']
        );

        $this->actingAs($context['admin']);
        $obligation = app(
            PurchaseObligationManager::class
        )->recognize(
            new PurchaseObligationData(
                purchaseReceiptId: $receipt->id,
                kind:
                    PurchaseObligationKind::Merchandise,
                beneficiaryBusinessPartyId: null,
                paymentCondition:
                    PurchaseObligationCondition::OnReceipt
            ),
            $context['admin']
        );

        return compact(
            'product',
            'location',
            'order',
            'receipt',
            'obligation'
        );
    }

    private function member(
        Organization $organization,
        UserRole $role
    ): User {
        $user = User::factory()->create([
            'role' => $role,
            'email_verified_at' => now(),
        ]);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => $role,
            'active' => true,
        ]);
        $user->forceFill([
            'current_organization_id' =>
                $organization->id,
        ])->saveQuietly();
        app(CurrentOrganization::class)->forget($user);

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
                'Se esperaba rechazo de integridad SQL.'
            );
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
