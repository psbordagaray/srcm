<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\ExternalFinancialMovementData;
use App\Domain\Finance\ExternalFinancialMovementRecorder;
use App\Domain\Finance\FinancialAccountManager;
use App\Domain\Finance\FinancialManualExternalMovementData;
use App\Domain\Finance\FinancialManualExternalMovementManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\FinancialAccountType;
use App\Enums\FinancialMovementDirection;
use App\Enums\FinancialMovementSource;
use App\Enums\FinancialMovementStatus;
use App\Enums\UserRole;
use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinancialManualExternalMovementFallbackTest
    extends TestCase
{
    use RefreshDatabase;

    public function test_admin_records_manual_posted_external_truth_without_auto_reconciliation(): void
    {
        [, $admin] = $this->organizationWithUsers();

        $this->actingAs($admin);

        $account = $this->account(
            $admin,
            'Manual'
        );

        $movement = app(
            FinancialManualExternalMovementManager::class
        )->record(
            $account,
            $this->manualData(
                externalOperationId:
                    'MANUAL-POSTED-1'
            ),
            $admin
        );

        $this->assertSame(
            FinancialMovementSource::Manual,
            $movement->source
        );

        $this->assertSame(
            FinancialMovementStatus::Posted,
            $movement->status
        );

        $this->assertSame(
            FinancialMovementDirection::Credit,
            $movement->direction
        );

        $this->assertSame(
            10000,
            $movement->gross_amount_minor
        );

        $this->assertSame(
            200,
            $movement->fee_amount_minor
        );

        $this->assertSame(
            9800,
            $movement->net_amount_minor
        );

        $this->assertDatabaseHas(
            'audit_logs',
            [
                'event' =>
                    'financial_manual_external_movement_recorded',
            ]
        );

        $this->assertDatabaseCount(
            'payment_reconciliations',
            0
        );
    }

    public function test_same_manual_idempotency_key_does_not_duplicate_movement(): void
    {
        [, $admin] = $this->organizationWithUsers();

        $account = $this->account(
            $admin,
            'Replay'
        );

        $key = (string) Str::uuid();

        $manager = app(
            FinancialManualExternalMovementManager::class
        );

        $first = $manager->record(
            $account,
            $this->manualData(
                idempotencyKey: $key
            ),
            $admin
        );

        $second = $manager->record(
            $account,
            $this->manualData(
                idempotencyKey: $key
            ),
            $admin
        );

        $this->assertSame(
            $first->id,
            $second->id
        );

        $this->assertDatabaseCount(
            'financial_external_movements',
            1
        );

        $this->assertDatabaseCount(
            'payment_reconciliations',
            0
        );
    }

    public function test_manual_fallback_deduplicates_same_cross_source_operation_and_conflicts_on_changed_money(): void
    {
        [, $admin] = $this->organizationWithUsers();

        $account = $this->account(
            $admin,
            'Cross source'
        );

        $existing = app(
            ExternalFinancialMovementRecorder::class
        )->record(
            $account,
            new ExternalFinancialMovementData(
                source:
                    FinancialMovementSource::Csv,
                sourceKey:
                    'csv:test:2',
                direction:
                    FinancialMovementDirection::Credit,
                status:
                    FinancialMovementStatus::Posted,
                currencyCode: 'ARS',
                grossAmountMinor: 10000,
                netAmountMinor: 9800,
                feeAmountMinor: 200,
                withholdingAmountMinor: 0,
                externalOperationId:
                    'CROSS-MANUAL-1',
                rawReference:
                    'Importado',
                occurredAt:
                    CarbonImmutable::parse(
                        '2026-08-15T13:30:00Z'
                    )
            ),
            $admin
        );

        $manager = app(
            FinancialManualExternalMovementManager::class
        );

        $deduplicated = $manager->record(
            $account,
            $this->manualData(
                externalOperationId:
                    'CROSS-MANUAL-1'
            ),
            $admin
        );

        $this->assertSame(
            $existing->id,
            $deduplicated->id
        );

        $this->assertDatabaseCount(
            'financial_external_movements',
            1
        );

        $this->assertDatabaseHas(
            'audit_logs',
            [
                'event' =>
                    'financial_manual_external_movement_deduplicated',
            ]
        );

        try {
            $manager->record(
                $account,
                $this->manualData(
                    externalOperationId:
                        'CROSS-MANUAL-1',
                    grossAmountMinor: 10100,
                    netAmountMinor: 9900
                ),
                $admin
            );

            $this->fail(
                'El mismo ID externo con dinero distinto debía fallar cerrado.'
            );
        } catch (DomainException $exception) {
            $this->assertStringContainsString(
                'contenido financiero diferente',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseCount(
            'financial_external_movements',
            1
        );
    }

    public function test_cash_foreign_and_operator_manual_fallback_fail_closed(): void
    {
        [$organization, $admin, $operator] =
            $this->organizationWithUsers();

        [, $foreignAdmin] =
            $this->organizationWithUsers();

        $cash = app(
            FinancialAccountManager::class
        )->create(
            'Caja manual',
            FinancialAccountType::CashBox,
            'ARS',
            $admin
        );

        $manager = app(
            FinancialManualExternalMovementManager::class
        );

        $this->expectDomainException(
            fn () => $manager->record(
                $cash,
                $this->manualData(),
                $admin
            )
        );

        $foreignAccount = $this->account(
            $foreignAdmin,
            'Foreign'
        );

        $this->expectDomainException(
            fn () => $manager->record(
                $foreignAccount,
                $this->manualData(),
                $admin
            )
        );

        $ownAccount = FinancialAccount::query()
            ->forOrganization(
                $organization->id
            )
            ->where('type', '!=', 'cash_box')
            ->first();

        if (! $ownAccount) {
            $ownAccount = $this->account(
                $admin,
                'Own operator'
            );
        }

        $this->expectDomainException(
            fn () => $manager->record(
                $ownAccount,
                $this->manualData(),
                $operator
            )
        );

        $this->assertDatabaseCount(
            'financial_external_movements',
            0
        );
    }

    public function test_manual_reason_and_financial_math_fail_closed(): void
    {
        [, $admin] = $this->organizationWithUsers();

        $account = $this->account(
            $admin,
            'Reason/math'
        );

        $manager = app(
            FinancialManualExternalMovementManager::class
        );

        $this->expectDomainException(
            fn () => $manager->record(
                $account,
                $this->manualData(
                    reason: 'corto'
                ),
                $admin
            )
        );

        $this->expectDomainException(
            fn () => $manager->record(
                $account,
                $this->manualData(
                    grossAmountMinor: 10000,
                    feeAmountMinor: 200,
                    netAmountMinor: 9700
                ),
                $admin
            )
        );

        $this->assertDatabaseCount(
            'financial_external_movements',
            0
        );
    }

    public function test_http_surface_is_admin_only_tenant_private_and_lists_non_cash_accounts(): void
    {
        [, $admin, $operator] =
            $this->organizationWithUsers();

        [, $foreignAdmin] =
            $this->organizationWithUsers();

        $account = $this->account(
            $admin,
            'HTTP manual'
        );

        $foreign = $this->account(
            $foreignAdmin,
            'HTTP foreign'
        );

        $this->actingAs($admin);

        $this->get(
            route(
                'financial-manual-external-movements.create'
            )
        )
            ->assertOk()
            ->assertSee('Registrar movimiento externo manual')
            ->assertSee($account->name)
            ->assertDontSee($foreign->name)
            ->assertSee('Fallback explícito');

        $this->post(
            route(
                'financial-manual-external-movements.store'
            ),
            [
                'financial_account' =>
                    $account->public_id,
                'direction' => 'credit',
                'gross_amount' => '100,00',
                'fee_amount' => '2,00',
                'withholding_amount' => '0',
                'net_amount' => '98,00',
                'occurred_at' =>
                    '2026-08-15T10:30',
                'external_operation_id' =>
                    'HTTP-MANUAL-1',
                'reference' =>
                    'Referencia segura',
                'manual_reason' =>
                    'La institución no ofrece exportación utilizable para este movimiento.',
                'idempotency_key' =>
                    (string) Str::uuid(),
            ]
        )
            ->assertRedirect(
                route(
                    'financial-reconciliation.index'
                )
            )
            ->assertSessionHas('success');

        $this->assertDatabaseHas(
            'financial_external_movements',
            [
                'financial_account_id' =>
                    $account->id,
                'source' =>
                    FinancialMovementSource::Manual->value,
                'status' =>
                    FinancialMovementStatus::Posted->value,
                'external_operation_id' =>
                    'HTTP-MANUAL-1',
            ]
        );

        $this->actingAs($operator);

        $this->get(
            route(
                'financial-manual-external-movements.create'
            )
        )->assertForbidden();

        $this->post(
            route(
                'financial-manual-external-movements.store'
            ),
            [
                'financial_account' =>
                    $account->public_id,
            ]
        )->assertForbidden();
    }

    private function manualData(
        ?string $externalOperationId = null,
        ?string $idempotencyKey = null,
        int $grossAmountMinor = 10000,
        int $feeAmountMinor = 200,
        int $withholdingAmountMinor = 0,
        int $netAmountMinor = 9800,
        string $reason =
            'La institución no ofrece una alternativa razonable de importación.'
    ): FinancialManualExternalMovementData {
        return new FinancialManualExternalMovementData(
            direction:
                FinancialMovementDirection::Credit,
            grossAmountMinor:
                $grossAmountMinor,
            feeAmountMinor:
                $feeAmountMinor,
            withholdingAmountMinor:
                $withholdingAmountMinor,
            netAmountMinor:
                $netAmountMinor,
            occurredAt:
                CarbonImmutable::parse(
                    '2026-08-15T13:30:00Z'
                ),
            externalOperationId:
                $externalOperationId,
            reference:
                'Referencia manual segura',
            reason: $reason,
            idempotencyKey:
                $idempotencyKey
                    ?? (string) Str::uuid()
        );
    }

    private function expectDomainException(
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

    private function account(
        User $admin,
        string $name
    ): FinancialAccount {
        return app(
            FinancialAccountManager::class
        )->create(
            $name.' '.Str::lower(
                Str::random(5)
            ),
            FinancialAccountType::BankAccount,
            'ARS',
            $admin,
            'Banco'
        );
    }

    /**
     * @return array{Organization, User, User}
     */
    private function organizationWithUsers(): array
    {
        $suffix = Str::lower(
            Str::random(8)
        );

        $organization = Organization::query()
            ->create([
                'name' =>
                    'Org P7.5 '.$suffix,
                'slug' =>
                    'org-p75-'.$suffix,
                'active' => true,
            ]);

        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'email_verified_at' => now(),
        ]);

        $operator =
            User::factory()->create([
                'role' =>
                    UserRole::Operator,
                'email_verified_at' => now(),
            ]);

        foreach ([
            [$admin, UserRole::Admin],
            [$operator, UserRole::Operator],
        ] as [$user, $role]) {
            OrganizationMembership::query()
                ->create([
                    'organization_id' =>
                        $organization->id,
                    'user_id' =>
                        $user->id,
                    'role' => $role,
                    'active' => true,
                ]);

            $user->forceFill([
                'current_organization_id' =>
                    $organization->id,
            ])->saveQuietly();

            app(CurrentOrganization::class)
                ->forget($user);
        }

        return [
            $organization,
            $admin->refresh(),
            $operator->refresh(),
        ];
    }
}
