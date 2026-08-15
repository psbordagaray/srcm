<?php

namespace Tests\Feature\Finance;

use App\Domain\Commerce\CommerceCheckoutData;
use App\Domain\Commerce\CommerceCheckoutManager;
use App\Domain\Commerce\CommercePaymentData;
use App\Domain\Commerce\CommerceProductLineData;
use App\Domain\Finance\ExternalFinancialMovementData;
use App\Domain\Finance\ExternalFinancialMovementRecorder;
use App\Domain\Finance\FinancialAccountManager;
use App\Domain\Finance\FinancialReconciliationDecisionManager;
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
use App\Enums\PaymentReconciliationStatus;
use App\Enums\UserRole;
use App\Models\CatalogProduct;
use App\Models\CommercePayment;
use App\Models\FinancialAccount;
use App\Models\FinancialExternalMovement;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductCategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinancialReconciliationExplicitDecisionTest
    extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-08-15 18:00:00',
                'UTC'
            )
        );
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_admin_explicitly_reconciles_exact_candidate_idempotently(): void
    {
        [$organization, $admin] =
            $this->organizationWithUsers();

        $this->actingAs($admin);

        $account = $this->bankAccount($admin, 'Exact');
        $paidAt = CarbonImmutable::parse(
            '2026-08-15 13:00:00',
            'UTC'
        );

        $payment = $this->electronicPayment(
            $organization,
            $admin,
            $account,
            6000000,
            'P6-EXACT',
            $paidAt
        );

        $movement = $this->externalMovement(
            $account,
            $admin,
            'p6-exact-movement',
            6000000,
            'P6-EXACT',
            $paidAt->addMinute()
        );

        $route = route(
            'financial-reconciliation.candidates.reconcile',
            [
                'commercePayment' => $payment->id,
                'financialExternalMovement' =>
                    $movement->public_id,
            ]
        );

        $this->post($route)
            ->assertRedirect(
                route('financial-reconciliation.index')
            )
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount(
            'payment_reconciliations',
            1
        );
        $this->assertDatabaseCount(
            'payment_reconciliation_events',
            1
        );
        $this->assertDatabaseCount(
            'payment_reconciliation_allocations',
            1
        );

        $this->assertDatabaseHas(
            'payment_reconciliation_events',
            [
                'status' =>
                    PaymentReconciliationStatus::Matched->value,
                'allocated_gross_amount_minor' => 6000000,
                'difference_minor' => 0,
            ]
        );

        $this->post($route)
            ->assertRedirect(
                route('financial-reconciliation.index')
            )
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount(
            'payment_reconciliation_events',
            1
        );
        $this->assertDatabaseCount(
            'payment_reconciliation_allocations',
            1
        );
    }

    public function test_difference_requires_explanation_and_preserves_exact_difference(): void
    {
        [$organization, $admin] =
            $this->organizationWithUsers();

        $this->actingAs($admin);

        $account = $this->bankAccount($admin, 'Difference');
        $paidAt = CarbonImmutable::parse(
            '2026-08-15 14:00:00',
            'UTC'
        );

        $payment = $this->electronicPayment(
            $organization,
            $admin,
            $account,
            6000000,
            'P6-DIFF',
            $paidAt
        );

        $movement = $this->externalMovement(
            $account,
            $admin,
            'p6-difference-movement',
            5900000,
            'P6-DIFF',
            $paidAt->addMinutes(2)
        );

        $route = route(
            'financial-reconciliation.candidates.reconcile',
            [
                'commercePayment' => $payment->id,
                'financialExternalMovement' =>
                    $movement->public_id,
            ]
        );

        $this->from(
            route('financial-reconciliation.index')
        )->post($route, [
            'note' => 'corto',
        ])
            ->assertRedirect(
                route('financial-reconciliation.index')
            )
            ->assertSessionHasErrors('reconciliation');

        $this->assertDatabaseCount(
            'payment_reconciliation_events',
            0
        );

        $note =
            'Diferencia confirmada contra la acreditación externa.';

        $this->post($route, [
            'note' => $note,
        ])
            ->assertRedirect(
                route('financial-reconciliation.index')
            )
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas(
            'payment_reconciliation_events',
            [
                'status' =>
                    PaymentReconciliationStatus::Difference->value,
                'allocated_gross_amount_minor' => 5900000,
                'difference_minor' => -100000,
                'note' => $note,
            ]
        );
    }

    public function test_decision_rechecks_account_currency_status_and_safe_time_window(): void
    {
        [$organization, $admin] =
            $this->organizationWithUsers();

        $this->actingAs($admin);

        $account = $this->bankAccount($admin, 'Guard');
        $otherAccount = $this->bankAccount(
            $admin,
            'Other'
        );

        $paidAt = CarbonImmutable::parse(
            '2026-08-15 15:00:00',
            'UTC'
        );

        $payment = $this->electronicPayment(
            $organization,
            $admin,
            $account,
            1000000,
            'P6-GUARD',
            $paidAt
        );

        $wrongAccount = $this->externalMovement(
            $otherAccount,
            $admin,
            'p6-wrong-account',
            1000000,
            'P6-GUARD',
            $paidAt
        );

        $outsideWindow = $this->externalMovement(
            $account,
            $admin,
            'p6-outside-window',
            1000000,
            'P6-GUARD',
            $paidAt->addDays(8)
        );

        $pending = $this->externalMovement(
            $account,
            $admin,
            'p6-pending',
            1000000,
            'P6-GUARD',
            $paidAt,
            FinancialMovementStatus::Pending
        );

        $manager = app(
            FinancialReconciliationDecisionManager::class
        );

        foreach (
            [$wrongAccount, $outsideWindow, $pending]
            as $movement
        ) {
            try {
                $manager->reconcileCandidate(
                    $payment,
                    $movement,
                    $admin
                );

                $this->fail(
                    'El candidato manipulado debía rechazarse.'
                );
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertDatabaseCount(
            'payment_reconciliation_events',
            0
        );
    }

    public function test_http_is_permissioned_and_foreign_payment_is_hidden(): void
    {
        [$organization, $admin, $operator] =
            $this->organizationWithUsers();

        [$foreignOrganization, $foreignAdmin] =
            $this->organizationWithUsers();

        $this->actingAs($admin);

        $account = $this->bankAccount($admin, 'Own');
        $paidAt = CarbonImmutable::parse(
            '2026-08-15 16:00:00',
            'UTC'
        );

        $payment = $this->electronicPayment(
            $organization,
            $admin,
            $account,
            1000000,
            'OWN-P6',
            $paidAt
        );

        $movement = $this->externalMovement(
            $account,
            $admin,
            'own-p6-movement',
            1000000,
            'OWN-P6',
            $paidAt
        );

        $this->actingAs($operator);

        $this->post(
            route(
                'financial-reconciliation.candidates.reconcile',
                [
                    'commercePayment' => $payment->id,
                    'financialExternalMovement' =>
                        $movement->public_id,
                ]
            )
        )->assertForbidden();

        $this->actingAs($foreignAdmin);

        $this->post(
            route(
                'financial-reconciliation.candidates.reconcile',
                [
                    'commercePayment' => $payment->id,
                    'financialExternalMovement' =>
                        $movement->public_id,
                ]
            )
        )->assertNotFound();

        $this->assertDatabaseCount(
            'payment_reconciliation_events',
            0
        );
    }

    public function test_center_get_never_auto_reconciles_and_exposes_explicit_action(): void
    {
        [$organization, $admin] =
            $this->organizationWithUsers();

        $this->actingAs($admin);

        $account = $this->bankAccount($admin, 'UI');
        $paidAt = CarbonImmutable::parse(
            '2026-08-15 17:00:00',
            'UTC'
        );

        $payment = $this->electronicPayment(
            $organization,
            $admin,
            $account,
            1000000,
            'UI-P6',
            $paidAt
        );

        $movement = $this->externalMovement(
            $account,
            $admin,
            'ui-p6-movement',
            1000000,
            'UI-P6',
            $paidAt->addMinute()
        );

        $this->get(
            route('financial-reconciliation.index')
        )
            ->assertOk()
            ->assertSee('Conciliar este movimiento')
            ->assertSee($movement->public_id);

        $this->assertDatabaseCount(
            'payment_reconciliation_events',
            0
        );
    }

    private function externalMovement(
        FinancialAccount $account,
        User $admin,
        string $sourceKey,
        int $gross,
        ?string $operationId,
        CarbonImmutable $occurredAt,
        FinancialMovementStatus $status =
            FinancialMovementStatus::Posted
    ): FinancialExternalMovement {
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
                netAmountMinor: $gross,
                feeAmountMinor: 0,
                withholdingAmountMinor: 0,
                externalOperationId: $operationId,
                occurredAt: $occurredAt
            ),
            $admin
        );
    }

    private function bankAccount(
        User $admin,
        string $suffix
    ): FinancialAccount {
        return app(FinancialAccountManager::class)->create(
            'Banco '.$suffix.' '.Str::lower(Str::random(5)),
            FinancialAccountType::BankAccount,
            'ARS',
            $admin,
            'Banco'
        );
    }

    private function electronicPayment(
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
            'name' => 'Caja P6.2 '.$suffix,
            'type' => InventoryLocationType::Receiving,
            'active' => true,
        ]);

        $category = ProductCategory::withoutEvents(
            fn () => ProductCategory::query()->firstOrCreate(
                ['slug' => 'p6-explicit-reconciliation-tests'],
                [
                    'name' => 'Pruebas P6.2',
                    'active' => true,
                ]
            )
        );

        $product = CatalogProduct::withoutEvents(
            fn () => CatalogProduct::query()->create([
                'product_category_id' => $category->id,
                'sku' => 'P62-'.$suffix,
                'name' => 'Producto P6.2 '.$suffix,
                'active' => true,
            ])->refresh()
        );

        $stock = app(InventoryMovementCreator::class)->create(
            new InventoryMovementDraftData(
                type: InventoryMovementType::Receipt,
                effectiveAt: $paidAt->subSecond(),
                reason: 'Stock para P6.2.',
                idempotencyKey: 'p62:stock:'.$suffix,
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
                idempotencyKey: 'p62:sale:'.$suffix,
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

        return $sale->payments->sole()
            ->refresh()
            ->load('sale');
    }

    /**
     * @return array{Organization, User, User}
     */
    private function organizationWithUsers(): array
    {
        $suffix = Str::lower(Str::random(8));

        $organization = Organization::query()->create([
            'name' => 'Org P6.2 '.$suffix,
            'slug' => 'org-p62-'.$suffix,
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
