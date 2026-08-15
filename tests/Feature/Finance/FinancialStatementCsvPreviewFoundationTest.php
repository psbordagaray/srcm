<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\FinancialAccountManager;
use App\Domain\Finance\FinancialStatementCsvPreviewer;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\FinancialAccountType;
use App\Enums\UserRole;
use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinancialStatementCsvPreviewFoundationTest
    extends TestCase
{
    use RefreshDatabase;

    public function test_admin_previews_canonical_csv_without_persisting_financial_truth(): void
    {
        [$organization, $admin] =
            $this->organizationWithUsers();

        $this->actingAs($admin);

        $account = $this->account(
            $admin,
            FinancialAccountType::BankAccount,
            'Banco CSV'
        );

        $csv = implode("\n", [
            $this->header(),
            '2026-08-15T10:30:00-03:00,credit,60000.00,3180.00,0.00,56820.00,OP-123,"Acreditación lote 123"',
            '2026-08-15T11:00:00-03:00,debit,1000.00,0.00,0.00,1000.00,OP-124,"Débito bancario"',
        ]);

        $response = $this->post(
            route('financial-statement-imports.csv.preview'),
            [
                'financial_account' => $account->public_id,
                'statement' =>
                    UploadedFile::fake()->createWithContent(
                        'extracto.csv',
                        $csv
                    ),
            ]
        );

        $response->assertOk()
            ->assertSee('Vista previa CSV validada')
            ->assertSee('OP-123')
            ->assertSee('60.000,00')
            ->assertSee('3.180,00')
            ->assertSee('56.820,00')
            ->assertSee('OP-124');

        $this->assertDatabaseCount(
            'financial_external_movements',
            0
        );

        $this->assertDatabaseCount(
            'payment_reconciliations',
            0
        );

        $this->assertDatabaseCount(
            'payment_reconciliation_events',
            0
        );
    }

    public function test_same_exact_file_has_stable_file_identity_source_keys_and_fingerprints(): void
    {
        [, $admin] = $this->organizationWithUsers();

        $account = $this->account(
            $admin,
            FinancialAccountType::BankAccount,
            'Deterministic CSV'
        );

        $contents = implode("\n", [
            $this->header(),
            '2026-08-15T10:30:00-03:00,credit,100.00,2.00,1.00,97.00,ID-1,"Fila uno"',
            '2026-08-15T10:31:00-03:00,credit,200.00,0.00,0.00,200.00,ID-2,"Fila dos"',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'srcm-p71-');

        $this->assertNotFalse($path);
        file_put_contents($path, $contents);

        try {
            $previewer = app(
                FinancialStatementCsvPreviewer::class
            );

            $first = $previewer->preview(
                $account,
                $path,
                'statement.csv',
                $admin
            );

            $second = $previewer->preview(
                $account,
                $path,
                'statement.csv',
                $admin
            );
        } finally {
            @unlink($path);
        }

        $this->assertSame(
            $first->fileSha256,
            $second->fileSha256
        );

        $this->assertSame(
            $first->rows[0]->sourceKey,
            $second->rows[0]->sourceKey
        );

        $this->assertSame(
            $first->rows[1]->fingerprint,
            $second->rows[1]->fingerprint
        );

        $this->assertDatabaseCount(
            'financial_external_movements',
            0
        );
    }

    public function test_invalid_financial_math_and_duplicate_external_ids_fail_closed(): void
    {
        [, $admin] = $this->organizationWithUsers();

        $this->actingAs($admin);

        $account = $this->account(
            $admin,
            FinancialAccountType::BankAccount,
            'Invalid CSV'
        );

        $invalidMath = implode("\n", [
            $this->header(),
            '2026-08-15T10:30:00-03:00,credit,100.00,2.00,0.00,99.00,ID-1,"No suma"',
        ]);

        $this->from(
            route('financial-statement-imports.csv.create')
        )->post(
            route('financial-statement-imports.csv.preview'),
            [
                'financial_account' => $account->public_id,
                'statement' =>
                    UploadedFile::fake()->createWithContent(
                        'bad.csv',
                        $invalidMath
                    ),
            ]
        )
            ->assertRedirect(
                route('financial-statement-imports.csv.create')
            )
            ->assertSessionHasErrors('statement_import');

        $duplicate = implode("\n", [
            $this->header(),
            '2026-08-15T10:30:00-03:00,credit,100.00,0.00,0.00,100.00,DUP-1,"Uno"',
            '2026-08-15T10:31:00-03:00,credit,200.00,0.00,0.00,200.00,DUP-1,"Dos"',
        ]);

        $this->post(
            route('financial-statement-imports.csv.preview'),
            [
                'financial_account' => $account->public_id,
                'statement' =>
                    UploadedFile::fake()->createWithContent(
                        'duplicate.csv',
                        $duplicate
                    ),
            ]
        )->assertSessionHasErrors('statement_import');

        $this->assertDatabaseCount(
            'financial_external_movements',
            0
        );
    }

    public function test_cash_foreign_and_operator_access_fail_closed(): void
    {
        [, $admin, $operator] =
            $this->organizationWithUsers();

        [, $foreignAdmin] =
            $this->organizationWithUsers();

        $this->actingAs($admin);

        $cash = $this->account(
            $admin,
            FinancialAccountType::CashBox,
            'Caja no importable'
        );

        $csv = implode("\n", [
            $this->header(),
            '2026-08-15T10:30:00-03:00,credit,100.00,0.00,0.00,100.00,ID-CASH,"Caja"',
        ]);

        $this->post(
            route('financial-statement-imports.csv.preview'),
            [
                'financial_account' => $cash->public_id,
                'statement' =>
                    UploadedFile::fake()->createWithContent(
                        'cash.csv',
                        $csv
                    ),
            ]
        )->assertSessionHasErrors('statement_import');

        $this->actingAs($foreignAdmin);

        $foreignAccount = $this->account(
            $foreignAdmin,
            FinancialAccountType::BankAccount,
            'Cuenta extranjera'
        );

        $this->actingAs($admin);

        $this->post(
            route('financial-statement-imports.csv.preview'),
            [
                'financial_account' =>
                    $foreignAccount->public_id,
                'statement' =>
                    UploadedFile::fake()->createWithContent(
                        'foreign.csv',
                        $csv
                    ),
            ]
        )->assertNotFound();

        $this->actingAs($operator);

        $this->get(
            route('financial-statement-imports.csv.create')
        )->assertForbidden();

        $this->assertDatabaseCount(
            'financial_external_movements',
            0
        );
    }

    public function test_utf8_bom_and_quoted_comma_reference_are_safe_in_preview(): void
    {
        [, $admin] = $this->organizationWithUsers();

        $this->actingAs($admin);

        $account = $this->account(
            $admin,
            FinancialAccountType::BankAccount,
            'BOM CSV'
        );

        $csv = "\xEF\xBB\xBF".$this->header()."\n"
            .'2026-08-15T10:30:00-03:00,credit,150.00,10.00,5.00,135.00,ID-BOM,"Referencia, con coma"';

        $this->post(
            route('financial-statement-imports.csv.preview'),
            [
                'financial_account' => $account->public_id,
                'statement' =>
                    UploadedFile::fake()->createWithContent(
                        'bom.csv',
                        $csv
                    ),
            ]
        )
            ->assertOk()
            ->assertSee('ID-BOM')
            ->assertSee('Referencia, con coma')
            ->assertSee('150,00')
            ->assertSee('135,00');

        $this->assertDatabaseCount(
            'financial_external_movements',
            0
        );
    }

    private function header(): string
    {
        return implode(',', [
            'occurred_at',
            'direction',
            'gross_amount',
            'fee_amount',
            'withholding_amount',
            'net_amount',
            'external_operation_id',
            'reference',
        ]);
    }

    private function account(
        User $admin,
        FinancialAccountType $type,
        string $name
    ): FinancialAccount {
        return app(FinancialAccountManager::class)->create(
            $name.' '.Str::lower(Str::random(5)),
            $type,
            'ARS',
            $admin,
            $type === FinancialAccountType::BankAccount
                ? 'Banco'
                : null
        );
    }

    /**
     * @return array{Organization, User, User}
     */
    private function organizationWithUsers(): array
    {
        $suffix = Str::lower(Str::random(8));

        $organization = Organization::query()->create([
            'name' => 'Org P7.1 '.$suffix,
            'slug' => 'org-p71-'.$suffix,
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
