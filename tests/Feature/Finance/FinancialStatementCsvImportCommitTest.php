<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\FinancialAccountManager;
use App\Domain\Finance\FinancialStatementCsvImportManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\FinancialAccountType;
use App\Enums\FinancialMovementSource;
use App\Enums\FinancialMovementStatus;
use App\Enums\UserRole;
use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinancialStatementCsvImportCommitTest
    extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_preview_stages_encrypted_private_draft_without_financial_mutation(): void
    {
        [, $admin] = $this->organizationWithUsers();

        $this->actingAs($admin);

        $account = $this->account(
            $admin,
            'Preview stage'
        );

        $csv = implode("\n", [
            $this->header(),
            '2026-08-15T10:30:00-03:00,credit,100.00,2.00,1.00,97.00,STAGE-1,"Referencia privada"',
        ]);

        $response = $this->post(
            route(
                'financial-statement-imports.csv.preview'
            ),
            [
                'financial_account' =>
                    $account->public_id,
                'statement' =>
                    UploadedFile::fake()
                        ->createWithContent(
                            'extracto.csv',
                            $csv
                        ),
            ]
        );

        $response
            ->assertOk()
            ->assertSee('Importar estos movimientos')
            ->assertSee(
                'No se conciliará automáticamente'
            );

        $files = Storage::disk('local')->files(
            'import-previews/financial-statements'
        );

        $this->assertCount(1, $files);

        $encrypted = Storage::disk('local')->get(
            $files[0]
        );

        $this->assertStringNotContainsString(
            'STAGE-1',
            $encrypted
        );

        $this->assertStringNotContainsString(
            'Referencia privada',
            $encrypted
        );

        $this->assertDatabaseCount(
            'financial_external_movements',
            0
        );

        $this->assertDatabaseCount(
            'payment_reconciliations',
            0
        );
    }

    public function test_explicit_commit_records_posted_csv_movements_and_never_reconciles(): void
    {
        [, $admin] = $this->organizationWithUsers();

        $this->actingAs($admin);

        $account = $this->account(
            $admin,
            'Commit'
        );

        $csv = implode("\n", [
            $this->header(),
            '2026-08-15T10:30:00-03:00,credit,60000.00,3180.00,0.00,56820.00,CSV-COMMIT-1,"Acreditación"',
            '2026-08-15T11:00:00-03:00,debit,1000.00,0.00,0.00,1000.00,CSV-COMMIT-2,"Débito"',
        ]);

        $staged = $this->stage(
            $account,
            $admin,
            $csv
        );

        $token = $staged['token'];

        $this->post(
            route(
                'financial-statement-imports.csv.store'
            ),
            ['token' => $token]
        )
            ->assertRedirect(
                route(
                    'financial-reconciliation.index'
                )
            )
            ->assertSessionHas('success');

        $this->assertDatabaseCount(
            'financial_external_movements',
            2
        );

        $this->assertDatabaseHas(
            'financial_external_movements',
            [
                'financial_account_id' =>
                    $account->id,
                'source' =>
                    FinancialMovementSource::Csv->value,
                'status' =>
                    FinancialMovementStatus::Posted->value,
                'external_operation_id' =>
                    'CSV-COMMIT-1',
                'gross_amount_minor' => 6000000,
                'fee_amount_minor' => 318000,
                'withholding_amount_minor' => 0,
                'net_amount_minor' => 5682000,
            ]
        );

        $this->assertDatabaseHas(
            'audit_logs',
            [
                'event' =>
                    'financial_statement_csv_import_committed',
            ]
        );

        $this->assertDatabaseCount(
            'payment_reconciliations',
            0
        );

        Storage::disk('local')->assertMissing(
            'import-previews/financial-statements/'
                .$token.'.json'
        );
    }

    public function test_same_exact_file_committed_twice_is_financially_idempotent(): void
    {
        [, $admin] = $this->organizationWithUsers();

        $this->actingAs($admin);

        $account = $this->account(
            $admin,
            'Exact replay'
        );

        $csv = implode("\n", [
            $this->header(),
            '2026-08-15T10:30:00-03:00,credit,100.00,0.00,0.00,100.00,REPLAY-1,"Uno"',
            '2026-08-15T10:31:00-03:00,credit,200.00,0.00,0.00,200.00,REPLAY-2,"Dos"',
        ]);

        $manager = app(
            FinancialStatementCsvImportManager::class
        );

        $first = $manager->commit(
            $this->stage(
                $account,
                $admin,
                $csv
            )['token'],
            $admin
        );

        $second = $manager->commit(
            $this->stage(
                $account,
                $admin,
                $csv
            )['token'],
            $admin
        );

        $this->assertSame(
            [
                'total' => 2,
                'created' => 2,
                'deduplicated' => 0,
            ],
            $first
        );

        $this->assertSame(
            [
                'total' => 2,
                'created' => 0,
                'deduplicated' => 2,
            ],
            $second
        );

        $this->assertDatabaseCount(
            'financial_external_movements',
            2
        );
    }

    public function test_cross_file_external_operation_deduplicates_same_money_and_conflicts_on_changed_money(): void
    {
        [, $admin] = $this->organizationWithUsers();

        $this->actingAs($admin);

        $account = $this->account(
            $admin,
            'External identity'
        );

        $manager = app(
            FinancialStatementCsvImportManager::class
        );

        $firstCsv = implode("\n", [
            $this->header(),
            '2026-08-15T10:30:00-03:00,credit,100.00,2.00,0.00,98.00,CROSS-1,"Primera referencia"',
        ]);

        $manager->commit(
            $this->stage(
                $account,
                $admin,
                $firstCsv,
                'first.csv'
            )['token'],
            $admin
        );

        $sameMoneyChangedFile = implode("\n", [
            $this->header(),
            '2026-08-15T10:30:00-03:00,credit,100.00,2.00,0.00,98.00,CROSS-1,"Referencia modificada"',
        ]);

        $result = $manager->commit(
            $this->stage(
                $account,
                $admin,
                $sameMoneyChangedFile,
                'second.csv'
            )['token'],
            $admin
        );

        $this->assertSame(0, $result['created']);
        $this->assertSame(
            1,
            $result['deduplicated']
        );

        $this->assertDatabaseCount(
            'financial_external_movements',
            1
        );

        $changedMoney = implode("\n", [
            $this->header(),
            '2026-08-15T10:30:00-03:00,credit,101.00,2.00,0.00,99.00,CROSS-1,"Conflicto"',
        ]);

        $this->expectException(
            DomainException::class
        );

        try {
            $manager->commit(
                $this->stage(
                    $account,
                    $admin,
                    $changedMoney,
                    'conflict.csv'
                )['token'],
                $admin
            );
        } finally {
            $this->assertDatabaseCount(
                'financial_external_movements',
                1
            );
        }
    }

    public function test_conflict_rolls_back_new_rows_from_same_commit_atomically(): void
    {
        [, $admin] = $this->organizationWithUsers();

        $this->actingAs($admin);

        $account = $this->account(
            $admin,
            'Atomic conflict'
        );

        $manager = app(
            FinancialStatementCsvImportManager::class
        );

        $seed = implode("\n", [
            $this->header(),
            '2026-08-15T10:30:00-03:00,credit,100.00,0.00,0.00,100.00,ATOMIC-EXIST,"Seed"',
        ]);

        $manager->commit(
            $this->stage(
                $account,
                $admin,
                $seed
            )['token'],
            $admin
        );

        $conflict = implode("\n", [
            $this->header(),
            '2026-08-15T10:31:00-03:00,credit,50.00,0.00,0.00,50.00,ATOMIC-NEW,"Debe revertir"',
            '2026-08-15T10:32:00-03:00,credit,90.00,0.00,0.00,90.00,ATOMIC-EXIST,"Conflicto"',
        ]);

        try {
            $manager->commit(
                $this->stage(
                    $account,
                    $admin,
                    $conflict
                )['token'],
                $admin
            );

            $this->fail(
                'El conflicto debía abortar todo el commit.'
            );
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseCount(
            'financial_external_movements',
            1
        );

        $this->assertDatabaseMissing(
            'financial_external_movements',
            [
                'external_operation_id' =>
                    'ATOMIC-NEW',
            ]
        );
    }

    public function test_commit_token_is_user_and_tenant_private_and_operator_route_is_forbidden(): void
    {
        [, $admin, $operator] =
            $this->organizationWithUsers();

        [, $foreignAdmin] =
            $this->organizationWithUsers();

        $this->actingAs($admin);

        $account = $this->account(
            $admin,
            'Private token'
        );

        $csv = implode("\n", [
            $this->header(),
            '2026-08-15T10:30:00-03:00,credit,100.00,0.00,0.00,100.00,PRIVATE-1,"Privado"',
        ]);

        $token = $this->stage(
            $account,
            $admin,
            $csv
        )['token'];

        $this->actingAs($operator);

        $this->post(
            route(
                'financial-statement-imports.csv.store'
            ),
            ['token' => $token]
        )->assertForbidden();

        $this->actingAs($foreignAdmin);

        $this->post(
            route(
                'financial-statement-imports.csv.store'
            ),
            ['token' => $token]
        )
            ->assertRedirect(
                route(
                    'financial-statement-imports.csv.create'
                )
            )
            ->assertSessionHas('error');

        $this->assertDatabaseCount(
            'financial_external_movements',
            0
        );
    }

    public function test_corrupted_encrypted_draft_fails_closed_without_financial_effect(): void
    {
        [, $admin] = $this->organizationWithUsers();

        $this->actingAs($admin);

        $account = $this->account(
            $admin,
            'Corrupted draft'
        );

        $csv = implode("\n", [
            $this->header(),
            '2026-08-15T10:30:00-03:00,credit,100.00,0.00,0.00,100.00,CORRUPT-1,"Corrupt"',
        ]);

        $token = $this->stage(
            $account,
            $admin,
            $csv
        )['token'];

        $path =
            'import-previews/financial-statements/'
            .$token.'.json';

        Storage::disk('local')->put(
            $path,
            'contenido manipulado'
        );

        $this->expectException(
            DomainException::class
        );

        try {
            app(
                FinancialStatementCsvImportManager::class
            )->commit(
                $token,
                $admin
            );
        } finally {
            $this->assertDatabaseCount(
                'financial_external_movements',
                0
            );

            Storage::disk('local')->assertMissing(
                $path
            );
        }
    }

    /**
     * @return array{
     *     preview: \App\Domain\Finance\FinancialStatementImportPreview,
     *     token: string
     * }
     */
    private function stage(
        FinancialAccount $account,
        User $admin,
        string $contents,
        string $name = 'extracto.csv'
    ): array {
        $path = tempnam(
            sys_get_temp_dir(),
            'srcm-p72-'
        );

        $this->assertNotFalse($path);

        file_put_contents($path, $contents);

        try {
            return app(
                FinancialStatementCsvImportManager::class
            )->stage(
                $account,
                $path,
                $name,
                $admin
            );
        } finally {
            @unlink($path);
        }
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
        $suffix = Str::lower(Str::random(8));

        $organization = Organization::query()
            ->create([
                'name' => 'Org P7.2 '.$suffix,
                'slug' => 'org-p72-'.$suffix,
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
            OrganizationMembership::query()
                ->create([
                    'organization_id' =>
                        $organization->id,
                    'user_id' => $user->id,
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
