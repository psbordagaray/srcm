<?php

namespace Tests\Feature\Finance;

use App\Domain\Commerce\CommerceCheckoutData;
use App\Domain\Commerce\CommerceCheckoutManager;
use App\Domain\Commerce\CommercePaymentData;
use App\Domain\Commerce\CommerceProductLineData;
use App\Domain\Finance\ExternalFinancialMovementData;
use App\Domain\Inventory\InventoryMovementConfirmer;
use App\Domain\Inventory\InventoryMovementCreator;
use App\Domain\Inventory\InventoryMovementDraftData;
use App\Domain\Inventory\InventoryMovementLineData;
use App\Domain\Finance\ExternalFinancialMovementRecorder;
use App\Domain\Finance\FinancialAccountManager;
use App\Domain\Finance\PaymentReconciliationManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CommercePaymentMethod;
use App\Enums\CommerceSaleStatus;
use App\Enums\FinancialAccountType;
use App\Enums\InventoryCondition;
use App\Enums\InventoryLocationType;
use App\Enums\InventoryMovementType;
use App\Enums\FinancialMovementDirection;
use App\Enums\FinancialMovementSource;
use App\Enums\FinancialMovementStatus;
use App\Enums\PaymentReconciliationStatus;
use App\Enums\UserRole;
use App\Models\CatalogProduct;
use App\Models\CommercePayment;
use App\Models\InventoryLocation;
use App\Models\FinancialAccount;
use App\Models\FinancialExternalMovement;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\PaymentReconciliation;
use App\Models\PaymentReconciliationAllocation;
use App\Models\PaymentReconciliationEvent;
use App\Models\ProductCategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinancialAccountsReconciliationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_and_financial_permissions_are_explicit(): void
    {
        $this->assertTrue(Schema::hasColumns('financial_accounts', [
            'organization_id',
            'public_id',
            'name',
            'type',
            'provider',
            'currency_code',
            'active',
        ]));
        $this->assertTrue(Schema::hasColumns(
            'financial_external_movements',
            [
                'financial_account_id',
                'source',
                'source_key',
                'fingerprint',
                'external_operation_id',
                'direction',
                'status',
                'gross_amount_minor',
                'fee_amount_minor',
                'withholding_amount_minor',
                'net_amount_minor',
            ]
        ));
        $this->assertTrue(Schema::hasColumns(
            'payment_reconciliations',
            [
                'commerce_payment_id',
                'expected_amount_minor',
                'opened_by_user_id',
                'opened_at',
            ]
        ));
        $this->assertTrue(Schema::hasColumns(
            'payment_reconciliation_events',
            [
                'idempotency_key',
                'status',
                'allocated_gross_amount_minor',
                'difference_minor',
            ]
        ));
        $this->assertTrue(Schema::hasColumns(
            'payment_reconciliation_allocations',
            [
                'financial_external_movement_id',
                'gross_amount_minor',
            ]
        ));

        $this->assertTrue(UserRole::Admin->canUseFinancialAccounts());
        $this->assertTrue(UserRole::Operator->canUseFinancialAccounts());
        $this->assertFalse(UserRole::Viewer->canUseFinancialAccounts());

        $this->assertTrue(UserRole::Admin->canManageFinancialAccounts());
        $this->assertFalse(UserRole::Operator->canManageFinancialAccounts());
        $this->assertFalse(UserRole::Viewer->canManageFinancialAccounts());

        $this->assertTrue(
            UserRole::Admin->canReviewFinancialReconciliation()
        );
        $this->assertFalse(
            UserRole::Operator->canReviewFinancialReconciliation()
        );
    }

    public function test_admin_manages_private_accounts_without_physical_delete(): void
    {
        [$organization, $admin] = $this->actor(UserRole::Admin);
        [$otherOrganization, $otherAdmin] = $this->actor(UserRole::Admin);

        $this->actingAs($admin);

        $account = app(FinancialAccountManager::class)->create(
            'Mercado Pago principal',
            FinancialAccountType::DigitalWallet,
            'ARS',
            $admin,
            'Mercado Pago',
            'Cuenta recaudadora'
        );

        $this->assertSame($organization->id, $account->organization_id);
        $this->assertSame('ARS', $account->currency_code);
        $this->assertTrue($account->active);
        $this->assertSame(
            'mercadopagoprincipal',
            $account->normalized_name
        );

        $this->assertSame(
            0,
            FinancialAccount::query()
                ->forOrganization($otherOrganization)
                ->whereKey($account->id)
                ->count()
        );

        try {
            $account->delete();
            $this->fail('La cuenta financiera no debe eliminarse.');
        } catch (DomainException) {
            $this->assertDatabaseHas('financial_accounts', [
                'id' => $account->id,
            ]);
        }

        $this->actingAs($otherAdmin);

        $this->expectException(DomainException::class);
        app(FinancialAccountManager::class)->toggleActive(
            $account,
            $otherAdmin
        );
    }

    public function test_external_movements_are_idempotent_and_immutable(): void
    {
        [$organization, $admin] = $this->actor(UserRole::Admin);
        $this->actingAs($admin);

        $account = app(FinancialAccountManager::class)->create(
            'Payway',
            FinancialAccountType::CardProcessor,
            'ARS',
            $admin,
            'Payway'
        );

        $data = new ExternalFinancialMovementData(
            source: FinancialMovementSource::Api,
            sourceKey: 'payway:op:1001',
            direction: FinancialMovementDirection::Credit,
            status: FinancialMovementStatus::Posted,
            currencyCode: 'ARS',
            grossAmountMinor: 6000000,
            netAmountMinor: 5682000,
            feeAmountMinor: 318000,
            externalOperationId: '1001',
            rawReference: 'Visa lote 77',
            occurredAt: CarbonImmutable::now()->subMinute()
        );

        $first = app(ExternalFinancialMovementRecorder::class)
            ->record($account, $data, $admin);
        $retry = app(ExternalFinancialMovementRecorder::class)
            ->record($account, $data, $admin);

        $this->assertSame($first->id, $retry->id);
        $this->assertSame($organization->id, $first->organization_id);
        $this->assertSame(6000000, $first->gross_amount_minor);
        $this->assertSame(5682000, $first->net_amount_minor);
        $this->assertSame(318000, $first->fee_amount_minor);
        $this->assertDatabaseCount('financial_external_movements', 1);

        $this->expectException(QueryException::class);
        DB::table('financial_external_movements')
            ->where('id', $first->id)
            ->update(['net_amount_minor' => 1]);
    }

    public function test_reconciliation_matches_expected_gross_and_preserves_fees(): void
    {
        [$organization, $admin] = $this->actor(UserRole::Admin);
        $this->actingAs($admin);

        $payment = $this->confirmedPayment(
            $organization,
            $admin,
            6000000
        );

        $account = app(FinancialAccountManager::class)->create(
            'Mercado Pago',
            FinancialAccountType::DigitalWallet,
            'ARS',
            $admin,
            'Mercado Pago'
        );

        $movement = app(ExternalFinancialMovementRecorder::class)
            ->record(
                $account,
                new ExternalFinancialMovementData(
                    source: FinancialMovementSource::Api,
                    sourceKey: 'mp:credit:83927461',
                    direction: FinancialMovementDirection::Credit,
                    status: FinancialMovementStatus::Posted,
                    currencyCode: 'ARS',
                    grossAmountMinor: 6000000,
                    netAmountMinor: 5682000,
                    feeAmountMinor: 318000,
                    externalOperationId: '83927461'
                ),
                $admin
            );

        $event = app(PaymentReconciliationManager::class)->reconcile(
            $payment,
            [[
                'movement' => $movement,
                'gross_amount_minor' => 6000000,
            ]],
            'reconcile:payment:'.$payment->id.':v1',
            $admin
        );

        $retry = app(PaymentReconciliationManager::class)->reconcile(
            $payment,
            [[
                'movement' => $movement,
                'gross_amount_minor' => 6000000,
            ]],
            'reconcile:payment:'.$payment->id.':v1',
            $admin
        );

        $this->assertSame($event->id, $retry->id);
        $this->assertSame(
            PaymentReconciliationStatus::Matched,
            $event->status
        );
        $this->assertSame(6000000, $event->allocated_gross_amount_minor);
        $this->assertSame(0, $event->difference_minor);
        $this->assertSame(5682000, $movement->net_amount_minor);
        $this->assertSame(318000, $movement->fee_amount_minor);
        $this->assertDatabaseCount('payment_reconciliations', 1);
        $this->assertDatabaseCount('payment_reconciliation_events', 1);
        $this->assertDatabaseCount(
            'payment_reconciliation_allocations',
            1
        );

        $case = PaymentReconciliation::query()->sole();
        $this->assertSame($payment->id, $case->commerce_payment_id);
        $this->assertSame(6000000, $case->expected_amount_minor);
    }

    public function test_difference_is_explicit_and_cross_tenant_rejected(): void
    {
        [$organization, $admin] = $this->actor(UserRole::Admin);
        [$otherOrganization, $otherAdmin] = $this->actor(UserRole::Admin);

        $this->actingAs($admin);

        $payment = $this->confirmedPayment(
            $organization,
            $admin,
            1000000
        );

        $account = app(FinancialAccountManager::class)->create(
            'Banco diferencia',
            FinancialAccountType::BankAccount,
            'ARS',
            $admin,
            'Banco'
        );

        $movement = app(ExternalFinancialMovementRecorder::class)
            ->record(
                $account,
                new ExternalFinancialMovementData(
                    source: FinancialMovementSource::Csv,
                    sourceKey: 'bank-row-1',
                    direction: FinancialMovementDirection::Credit,
                    status: FinancialMovementStatus::Posted,
                    currencyCode: 'ARS',
                    grossAmountMinor: 900000,
                    netAmountMinor: 900000,
                    rawReference: 'Transferencia acreditada'
                ),
                $admin
            );

        $event = app(PaymentReconciliationManager::class)->reconcile(
            $payment,
            [[
                'movement' => $movement,
                'gross_amount_minor' => 900000,
            ]],
            'reconcile:difference:'.$payment->id,
            $admin,
            'Faltan ARS 1.000,00.'
        );

        $this->assertSame(
            PaymentReconciliationStatus::Difference,
            $event->status
        );
        $this->assertSame(-100000, $event->difference_minor);
        $this->assertSame(
            'Faltan ARS 1.000,00.',
            $event->note
        );

        $foreignPayment = $this->confirmedPayment(
            $otherOrganization,
            $otherAdmin,
            500000
        );

        $this->actingAs($admin);

        $this->expectException(DomainException::class);
        app(PaymentReconciliationManager::class)->reconcile(
            $foreignPayment,
            [[
                'movement' => $movement,
                'gross_amount_minor' => 100000,
            ]],
            'foreign:blocked',
            $admin
        );
    }

    /**
     * @return array{Organization, User}
     */
    private function actor(UserRole $role): array
    {
        $suffix = Str::lower(Str::random(8));

        $organization = Organization::query()->create([
            'name' => 'Org '.$suffix,
            'slug' => 'org-'.$suffix,
            'active' => true,
        ]);

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
            'current_organization_id' => $organization->id,
        ])->saveQuietly();

        app(CurrentOrganization::class)->forget($user);

        return [$organization, $user->refresh()];
    }

    private function confirmedPayment(
        Organization $organization,
        User $actor,
        int $amountMinor
    ): CommercePayment {
        $suffix = Str::lower(Str::random(10));

        $location = InventoryLocation::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Caja finance '.$suffix,
            'type' => InventoryLocationType::Receiving,
            'active' => true,
        ]);

        $category = ProductCategory::withoutEvents(
            fn () => ProductCategory::query()->firstOrCreate(
                ['slug' => 'finance-foundation-tests'],
                [
                    'name' => 'Pruebas financieras',
                    'active' => true,
                ]
            )
        );

        $product = CatalogProduct::withoutEvents(
            fn () => CatalogProduct::query()->create([
                'product_category_id' => $category->id,
                'sku' => 'FIN-'.$suffix,
                'name' => 'Producto financiero '.$suffix,
                'active' => true,
            ])->refresh()
        );

        $stock = app(InventoryMovementCreator::class)->create(
            new InventoryMovementDraftData(
                type: InventoryMovementType::Receipt,
                effectiveAt: CarbonImmutable::now()->subSecond(),
                reason: 'Stock previo para conciliación financiera.',
                idempotencyKey: 'finance:stock:'.$suffix,
                lines: [new InventoryMovementLineData(
                    catalogProductId: $product->id,
                    condition: InventoryCondition::New,
                    enteredQuantity: '1',
                    enteredUnitCode: $product->base_unit_code,
                    destinationLocationId: $location->id
                )]
            ),
            $actor
        );

        app(InventoryMovementConfirmer::class)->confirm(
            $stock,
            $actor
        );

        $sale = app(CommerceCheckoutManager::class)->checkout(
            new CommerceCheckoutData(
                currencyCode: 'ARS',
                idempotencyKey: 'finance:sale:'.$suffix,
                payments: [new CommercePaymentData(
                    CommercePaymentMethod::BankTransfer,
                    $amountMinor,
                    'REF-'.$suffix
                )],
                productLines: [new CommerceProductLineData(
                    catalogProductId: $product->id,
                    sourceLocationId: $location->id,
                    condition: InventoryCondition::New,
                    quantity: '1',
                    unitPriceMinor: $amountMinor
                )]
            ),
            $actor
        );

        return $sale->payments->sole()->refresh();
    }
}
