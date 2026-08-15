<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\FinancialAccountManager;
use App\Domain\Finance\FinancialStatementCsvImportManager;
use App\Domain\Finance\FinancialStatementCsvMapping;
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

class FinancialStatementCsvConfigurableMappingTest
    extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_http_previews_noncanonical_semicolon_csv_with_comma_decimals_and_local_time(): void
    {
        [, $admin] = $this->organizationWithUsers();

        $this->actingAs($admin);

        $account = $this->account(
            $admin,
            'Mapped HTTP'
        );

        $csv = implode("\n", [
            'Fecha;Tipo;Bruto;Comision;Neto;Operacion;Detalle;Extra',
            '15/08/2026 10:30:00;C;60000,00;3180,00;56820,00;MAP-1;"Acreditación mapeada";ignorar',
        ]);

        $this->post(
            route(
                'financial-statement-imports.csv.preview'
            ),
            [
                'financial_account' =>
                    $account->public_id,
                'statement' =>
                    UploadedFile::fake()
                        ->createWithContent(
                            'banco.csv',
                            $csv
                        ),
                ...$this->mappingInput(),
            ]
        )
            ->assertOk()
            ->assertSee(
                'Vista previa CSV validada'
            )
            ->assertSee('MAP-1')
            ->assertSee('60.000,00')
            ->assertSee('3.180,00')
            ->assertSee('56.820,00')
            ->assertSee('15/08/2026 13:30:00');

        $this->assertDatabaseCount(
            'financial_external_movements',
            0
        );
    }

    public function test_mapped_commit_uses_same_csv_ledger_and_defaults_optional_amount_columns_to_zero(): void
    {
        [, $admin] = $this->organizationWithUsers();

        $account = $this->account(
            $admin,
            'Mapped commit'
        );

        $csv = implode("\n", [
            'Fecha;Tipo;Bruto;Neto;Operacion;Extra',
            '15/08/2026 11:00:00;C;100,00;100,00;MAP-COMMIT;ignorar',
        ]);

        $mapping =
            FinancialStatementCsvMapping::fromInput([
                ...$this->mappingInput(),
                'mapping_fee_amount_header' => '',
                'mapping_reference_header' => '',
            ]);

        $manager = app(
            FinancialStatementCsvImportManager::class
        );

        $staged = $this->stage(
            $manager,
            $account,
            $admin,
            $csv,
            $mapping
        );

        $result = $manager->commit(
            $staged['token'],
            $admin
        );

        $this->assertSame(
            [
                'total' => 1,
                'created' => 1,
                'deduplicated' => 0,
            ],
            $result
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
                    'MAP-COMMIT',
                'gross_amount_minor' => 10000,
                'fee_amount_minor' => 0,
                'withholding_amount_minor' => 0,
                'net_amount_minor' => 10000,
            ]
        );

        $this->assertDatabaseCount(
            'payment_reconciliations',
            0
        );
    }

    public function test_mapping_requires_existing_distinct_headers_and_known_direction(): void
    {
        [, $admin] = $this->organizationWithUsers();

        $account = $this->account(
            $admin,
            'Mapping guards'
        );

        $manager = app(
            FinancialStatementCsvImportManager::class
        );

        $missingHeaderCsv = implode("\n", [
            'Fecha;Tipo;Bruto;Neto;Operacion',
            '15/08/2026 10:30:00;C;100,00;100,00;GUARD-1',
        ]);

        $this->expectDomainException(
            fn () => $this->stage(
                $manager,
                $account,
                $admin,
                $missingHeaderCsv,
                FinancialStatementCsvMapping::fromInput(
                    $this->mappingInput()
                )
            )
        );

        $unknownDirectionCsv = implode("\n", [
            'Fecha;Tipo;Bruto;Comision;Neto;Operacion;Detalle;Extra',
            '15/08/2026 10:30:00;X;100,00;0,00;100,00;GUARD-2;Dato;ignorar',
        ]);

        $this->expectDomainException(
            fn () => $this->stage(
                $manager,
                $account,
                $admin,
                $unknownDirectionCsv,
                FinancialStatementCsvMapping::fromInput(
                    $this->mappingInput()
                )
            )
        );

        $duplicate = $this->mappingInput();
        $duplicate['mapping_net_amount_header'] =
            $duplicate['mapping_gross_amount_header'];

        $this->expectDomainException(
            fn () =>
                FinancialStatementCsvMapping::fromInput(
                    $duplicate
                )
        );

        $this->assertDatabaseCount(
            'financial_external_movements',
            0
        );
    }

    public function test_same_file_remapped_to_different_financial_truth_fails_closed_on_existing_source_key(): void
    {
        [, $admin] = $this->organizationWithUsers();

        $account = $this->account(
            $admin,
            'Remap conflict'
        );

        $csv = implode("\n", [
            'Fecha;Tipo;Bruto;NetoA;FeeA;NetoB;FeeB;Operacion',
            '15/08/2026 10:30:00;C;100,00;90,00;10,00;80,00;20,00;REMAP-1',
        ]);

        $baseInput = [
            'mapping_delimiter' => 'semicolon',
            'mapping_decimal_separator' => 'comma',
            'mapping_date_format' => 'dmy_his',
            'mapping_timezone' =>
                'America/Argentina/Buenos_Aires',
            'mapping_occurred_at_header' =>
                'Fecha',
            'mapping_direction_header' =>
                'Tipo',
            'mapping_gross_amount_header' =>
                'Bruto',
            'mapping_withholding_amount_header' =>
                '',
            'mapping_external_operation_id_header' =>
                'Operacion',
            'mapping_reference_header' => '',
            'mapping_credit_value' => 'C',
            'mapping_debit_value' => 'D',
        ];

        $mappingA =
            FinancialStatementCsvMapping::fromInput([
                ...$baseInput,
                'mapping_fee_amount_header' =>
                    'FeeA',
                'mapping_net_amount_header' =>
                    'NetoA',
            ]);

        $mappingB =
            FinancialStatementCsvMapping::fromInput([
                ...$baseInput,
                'mapping_fee_amount_header' =>
                    'FeeB',
                'mapping_net_amount_header' =>
                    'NetoB',
            ]);

        $manager = app(
            FinancialStatementCsvImportManager::class
        );

        $manager->commit(
            $this->stage(
                $manager,
                $account,
                $admin,
                $csv,
                $mappingA
            )['token'],
            $admin
        );

        try {
            $manager->commit(
                $this->stage(
                    $manager,
                    $account,
                    $admin,
                    $csv,
                    $mappingB
                )['token'],
                $admin
            );

            $this->fail(
                'Remapear el mismo archivo a otra verdad financiera debía fallar cerrado.'
            );
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseCount(
            'financial_external_movements',
            1
        );

        $this->assertDatabaseHas(
            'financial_external_movements',
            [
                'external_operation_id' =>
                    'REMAP-1',
                'gross_amount_minor' => 10000,
                'fee_amount_minor' => 1000,
                'net_amount_minor' => 9000,
            ]
        );
    }

    public function test_mapping_fingerprint_is_deterministic_and_changes_with_configuration(): void
    {
        $first =
            FinancialStatementCsvMapping::fromInput(
                $this->mappingInput()
            );

        $second =
            FinancialStatementCsvMapping::fromInput(
                $this->mappingInput()
            );

        $changedInput = $this->mappingInput();
        $changedInput['mapping_credit_value'] =
            'HABER';

        $changed =
            FinancialStatementCsvMapping::fromInput(
                $changedInput
            );

        $this->assertSame(
            $first->fingerprint(),
            $second->fingerprint()
        );

        $this->assertNotSame(
            $first->fingerprint(),
            $changed->fingerprint()
        );

        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            $first->fingerprint()
        );
    }

    /**
     * @return array<string, string>
     */
    private function mappingInput(): array
    {
        return [
            'mapping_mode' => 'mapped',
            'mapping_delimiter' => 'semicolon',
            'mapping_decimal_separator' => 'comma',
            'mapping_date_format' => 'dmy_his',
            'mapping_timezone' =>
                'America/Argentina/Buenos_Aires',
            'mapping_occurred_at_header' =>
                'Fecha',
            'mapping_direction_header' =>
                'Tipo',
            'mapping_gross_amount_header' =>
                'Bruto',
            'mapping_fee_amount_header' =>
                'Comision',
            'mapping_withholding_amount_header' =>
                '',
            'mapping_net_amount_header' =>
                'Neto',
            'mapping_external_operation_id_header' =>
                'Operacion',
            'mapping_reference_header' =>
                'Detalle',
            'mapping_credit_value' => 'C',
            'mapping_debit_value' => 'D',
        ];
    }

    /**
     * @return array{
     *     preview: \App\Domain\Finance\FinancialStatementImportPreview,
     *     token: string
     * }
     */
    private function stage(
        FinancialStatementCsvImportManager $manager,
        FinancialAccount $account,
        User $admin,
        string $contents,
        FinancialStatementCsvMapping $mapping
    ): array {
        $path = tempnam(
            sys_get_temp_dir(),
            'srcm-p73-'
        );

        $this->assertNotFalse($path);

        file_put_contents($path, $contents);

        try {
            return $manager->stage(
                $account,
                $path,
                'mapped.csv',
                $admin,
                $mapping
            );
        } finally {
            @unlink($path);
        }
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
        $suffix = Str::lower(Str::random(8));

        $organization = Organization::query()
            ->create([
                'name' => 'Org P7.3 '.$suffix,
                'slug' => 'org-p73-'.$suffix,
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
