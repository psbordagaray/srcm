<?php

namespace Tests\Feature\Finance;

use App\Domain\Commerce\CommerceCheckoutData;
use App\Domain\Commerce\CommerceCheckoutManager;
use App\Domain\Commerce\CommercePaymentData;
use App\Domain\Commerce\CommerceProductLineData;
use App\Domain\Finance\ExternalFinancialMovementData;
use App\Domain\Finance\ExternalFinancialMovementRecorder;
use App\Domain\Finance\FinancialAccountManager;
use App\Domain\Finance\FinancialReconciliationCenterReader;
use App\Domain\Finance\PaymentReconciliationManager;
use App\Domain\Inventory\InventoryMovementConfirmer;
use App\Domain\Inventory\InventoryMovementCreator;
use App\Domain\Inventory\InventoryMovementDraftData;
use App\Domain\Inventory\InventoryMovementLineData;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CommercePaymentMethod;
use App\Enums\FinancialAccountType;
use App\Enums\FinancialMovementDirection;
use App\Enums\FinancialMovementSource;
use App\Enums\FinancialMovementStatus;
use App\Enums\InventoryCondition;
use App\Enums\InventoryLocationType;
use App\Enums\InventoryMovementType;
use App\Enums\UserRole;
use App\Models\CatalogProduct;
use App\Models\CommercePayment;
use App\Models\FinancialAccount;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductCategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinancialReconciliationCenterFoundationTest
    extends TestCase
{
    use RefreshDatabase;

    public function test_center_is_admin_only_tenant_private_and_read_only(): void
    {
        [$organization, $admin, $operator] =
            $this->organizationWithUsers();

        [$foreignOrganization, $foreignAdmin] =
            $this->organizationWithUsers();

        $this->actingAs($admin);

        [$account] = $this->bankAccount($admin, 'Principal');
        $payment = $this->confirmedElectronicPayment(
            $organization,
            $admin,
            $account,
            6000000,
            'OWN-OP',
            CarbonImmutable::parse('2026-08-15 10:00:00', 'UTC')
        );

        $this->actingAs($foreignAdmin);

        [$foreignAccount] =
            $this->bankAccount($foreignAdmin, 'Foreign');
        $foreignPayment = $this->confirmedElectronicPayment(
            $foreignOrganization,
            $foreignAdmin,
            $foreignAccount,
            5000000,
            'FOREIGN-OP',
            CarbonImmutable::parse('2026-08-15 10:01:00', 'UTC')
        );

        $this->actingAs($admin);

        $response = $this->get(
            route('financial-reconciliation.index')
        );

        $response->assertOk()
            ->assertSee('Centro de conciliación')
            ->assertSee($payment->sale->public_id)
            ->assertDontSee($foreignPayment->sale->public_id);

        $this->assertDatabaseCount(
            'payment_reconciliation_events',
            0
        );

        $this->actingAs($operator);

        $this->get(
            route('financial-reconciliation.index')
        )->assertForbidden();
    }

    public function test_candidates_are_same_account_currency_posted_credit_and_rank_evidence(): void
    {
        [$organization, $admin] =
            $this->organizationWithUsers();

        $this->actingAs($admin);

        [$account] = $this->bankAccount($admin, 'Candidates');
        [$otherAccount] =
            $this->bankAccount($admin, 'Other account');

        $paidAt = CarbonImmutable::parse(
            '2026-08-15 10:00:00',
            'UTC'
        );

        $payment = $this->confirmedElectronicPayment(
            $organization,
            $admin,
            $account,
            6000000,
            'OP-EXACT',
            $paidAt
        );

        $exact = $this->externalMovement(
            $account,
            $admin,
            'candidate-exact',
            6000000,
            5682000,
            318000,
            'OP-EXACT',
            $paidAt->addMinutes(2)
        );

        $grossOnly = $this->externalMovement(
            $account,
            $admin,
            'candidate-gross',
            6000000,
            5900000,
            100000,
            'OP-OTHER',
            $paidAt->addMinute()
        );

        $this->externalMovement(
            $otherAccount,
            $admin,
            'wrong-account',
            6000000,
            6000000,
            0,
            'OP-EXACT',
            $paidAt
        );

        $this->externalMovement(
            $account,
            $admin,
            'pending',
            6000000,
            6000000,
            0,
            'OP-EXACT',
            $paidAt,
            FinancialMovementStatus::Pending
        );

        $items = app(
            FinancialReconciliationCenterReader::class
        )->read($organization->id);

        $item = $items->firstWhere(
            'paymentId',
            $payment->id
        );

        $this->assertNotNull($item);
        $this->assertCount(2, $item->candidates);
        $this->assertSame(
            $exact->id,
            $item->candidates[0]->movementId
        );
        $this->assertSame(
            'strong',
            $item->candidates[0]->evidenceLevel
        );
        $this->assertContains(
            'external_operation_exact',
            $item->candidates[0]->evidenceCodes
        );
        $this->assertSame(
            $grossOnly->id,
            $item->candidates[1]->movementId
        );
        $this->assertSame(
            'medium',
            $item->candidates[1]->evidenceLevel
        );
    }

    public function test_center_keeps_gross_net_fees_and_withholding_separate(): void
    {
        [$organization, $admin] =
            $this->organizationWithUsers();

        $this->actingAs($admin);

        [$account] = $this->bankAccount($admin, 'Amounts');
        $paidAt = CarbonImmutable::parse(
            '2026-08-15 11:00:00',
            'UTC'
        );

        $payment = $this->confirmedElectronicPayment(
            $organization,
            $admin,
            $account,
            6000000,
            'AMOUNT-OP',
            $paidAt
        );

        $this->externalMovement(
            $account,
            $admin,
            'amount-separation',
            6000000,
            5632000,
            318000,
            'AMOUNT-OP',
            $paidAt->addMinute(),
            FinancialMovementStatus::Posted,
            50000
        );

        $item = app(
            FinancialReconciliationCenterReader::class
        )->read($organization->id)
            ->firstWhere('paymentId', $payment->id);

        $candidate = $item->candidates[0];

        $this->assertSame(6000000, $candidate->grossAmountMinor);
        $this->assertSame(5632000, $candidate->netAmountMinor);
        $this->assertSame(318000, $candidate->feeAmountMinor);
        $this->assertSame(
            50000,
            $candidate->withholdingAmountMinor
        );
        $this->assertSame(0, $candidate->grossDifferenceMinor);

        $this->get(
            route('financial-reconciliation.index')
        )
            ->assertOk()
            ->assertSee('60.000,00')
            ->assertSee('56.320,00')
            ->assertSee('3.180,00')
            ->assertSee('500,00');
    }

    public function test_movement_used_by_other_payment_is_not_offered_as_candidate(): void
    {
        [$organization, $admin] =
            $this->organizationWithUsers();

        $this->actingAs($admin);

        [$account] = $this->bankAccount($admin, 'Allocated');
        $paidAt = CarbonImmutable::parse(
            '2026-08-15 12:00:00',
            'UTC'
        );

        $firstPayment = $this->confirmedElectronicPayment(
            $organization,
            $admin,
            $account,
            1000000,
            'ALLOCATED-OP',
            $paidAt
        );

        $movement = $this->externalMovement(
            $account,
            $admin,
            'allocated-movement',
            1000000,
            980000,
            20000,
            'ALLOCATED-OP',
            $paidAt
        );

        app(PaymentReconciliationManager::class)->reconcile(
            $firstPayment,
            [[
                'movement' => $movement,
                'gross_amount_minor' => 1000000,
            ]],
            'p6:allocated:first',
            $admin
        );

        $secondPayment = $this->confirmedElectronicPayment(
            $organization,
            $admin,
            $account,
            1000000,
            'SECOND-OP',
            $paidAt->addMinute()
        );

        $secondItem = app(
            FinancialReconciliationCenterReader::class
        )->read($organization->id)
            ->firstWhere('paymentId', $secondPayment->id);

        $this->assertNotNull($secondItem);
        $this->assertCount(0, $secondItem->candidates);
    }

    private function externalMovement(
        FinancialAccount $account,
        User $admin,
        string $sourceKey,
        int $gross,
        int $net,
        int $fee,
        ?string $operationId,
        CarbonImmutable $occurredAt,
        FinancialMovementStatus $status =
            FinancialMovementStatus::Posted,
        int $withholding = 0
    ) {
        return app(
            ExternalFinancialMovementRecorder::class
        )->record(
            $account,
            new ExternalFinancialMovementData(
                source: FinancialMovementSource::Api,
                sourceKey: $sourceKey,
                direction: FinancialMovementDirection::Credit,
                status: $status,
                currencyCode: 'ARS',
                grossAmountMinor: $gross,
                netAmountMinor: $net,
                feeAmountMinor: $fee,
                withholdingAmountMinor: $withholding,
                externalOperationId: $operationId,
                occurredAt: $occurredAt
            ),
            $admin
        );
    }

    /**
     * @return array{FinancialAccount}
     */
    private function bankAccount(
        User $admin,
        string $suffix
    ): array {
        return [
            app(FinancialAccountManager::class)->create(
                'Banco '.$suffix.' '.Str::lower(
                    Str::random(5)
                ),
                FinancialAccountType::BankAccount,
                'ARS',
                $admin,
                'Banco'
            ),
        ];
    }

    private function confirmedElectronicPayment(
        Organization $organization,
        User $actor,
        FinancialAccount $account,
        int $amountMinor,
        string $operationId,
        CarbonImmutable $paidAt
    ): CommercePayment {
        $suffix = Str::lower(Str::random(10));

        $location = InventoryLocation::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Caja P6 '.$suffix,
            'type' => InventoryLocationType::Receiving,
            'active' => true,
        ]);

        $category = ProductCategory::withoutEvents(
            fn () => ProductCategory::query()->firstOrCreate(
                ['slug' => 'p6-reconciliation-tests'],
                [
                    'name' => 'Pruebas P6',
                    'active' => true,
                ]
            )
        );

        $product = CatalogProduct::withoutEvents(
            fn () => CatalogProduct::query()->create([
                'product_category_id' => $category->id,
                'sku' => 'P6-'.$suffix,
                'name' => 'Producto P6 '.$suffix,
                'active' => true,
            ])->refresh()
        );

        $stock = app(InventoryMovementCreator::class)->create(
            new InventoryMovementDraftData(
                type: InventoryMovementType::Receipt,
                effectiveAt: $paidAt->subSecond(),
                reason: 'Stock para Centro de Conciliación.',
                idempotencyKey: 'p6:stock:'.$suffix,
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
                idempotencyKey: 'p6:sale:'.$suffix,
                payments: [new CommercePaymentData(
                    method: CommercePaymentMethod::BankTransfer,
                    amountMinor: $amountMinor,
                    reference: 'REF-'.$suffix,
                    paidAt: $paidAt,
                    processor: 'Banco',
                    externalOperationId: $operationId,
                    financialAccountId: $account->id
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

        return $sale->payments->sole()->refresh()->load('sale');
    }

    /**
     * @return array{Organization, User, User}
     */
    private function organizationWithUsers(): array
    {
        $suffix = Str::lower(Str::random(8));

        $organization = Organization::query()->create([
            'name' => 'Org P6.1 '.$suffix,
            'slug' => 'org-p61-'.$suffix,
            'active' => true,
        ]);

        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'email_verified_at' => now(),
        ]);

        $operator = User::factory()->create([
            'role' => UserRole::Operator,
            'email_verified_at' => now(),
        ]);

        foreach ([
            [$admin, UserRole::Admin],
            [$operator, UserRole::Operator],
        ] as [$user, $role]) {
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
        }

        return [
            $organization,
            $admin->refresh(),
            $operator->refresh(),
        ];
    }
}
