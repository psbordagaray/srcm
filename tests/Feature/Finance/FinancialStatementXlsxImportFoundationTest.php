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
use ZipArchive;

class FinancialStatementXlsxImportFoundationTest
    extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->assertTrue(
            class_exists(ZipArchive::class),
            'P7.4 requires ext-zip.'
        );
    }

    public function test_canonical_xlsx_preview_and_commit_use_xlsx_source_without_auto_reconciliation(): void
    {
        [, $admin] = $this->organizationWithUsers();

        $this->actingAs($admin);

        $account = $this->account(
            $admin,
            'Canonical XLSX'
        );

        $path = $this->createXlsx(
            [
                [
                    'occurred_at',
                    'direction',
                    'gross_amount',
                    'fee_amount',
                    'withholding_amount',
                    'net_amount',
                    'external_operation_id',
                    'reference',
                ],
                [
                    '2026-08-15T10:30:00-03:00',
                    'credit',
                    60000.00,
                    3180.00,
                    0.00,
                    56820.00,
                    'XLSX-1',
                    'Acreditación XLSX',
                ],
            ]
        );

        try {
            $file = new UploadedFile(
                $path,
                'extracto.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                null,
                true
            );

            $response = $this->post(
                route(
                    'financial-statement-imports.csv.preview'
                ),
                [
                    'financial_account' =>
                        $account->public_id,
                    'statement' => $file,
                    'mapping_mode' => 'canonical',
                ]
            );

            $response
                ->assertOk()
                ->assertSee('Vista previa CSV validada')
                ->assertSee('XLSX-1')
                ->assertSee('60.000,00');

            $files = Storage::disk('local')->files(
                'import-previews/financial-statements'
            );

            $this->assertCount(1, $files);

            $token = pathinfo(
                $files[0],
                PATHINFO_FILENAME
            );

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
        } finally {
            @unlink($path);
        }

        $this->assertDatabaseHas(
            'financial_external_movements',
            [
                'financial_account_id' =>
                    $account->id,
                'source' =>
                    FinancialMovementSource::Xlsx->value,
                'status' =>
                    FinancialMovementStatus::Posted->value,
                'external_operation_id' =>
                    'XLSX-1',
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
                    'financial_statement_xlsx_import_committed',
            ]
        );

        $this->assertDatabaseCount(
            'payment_reconciliations',
            0
        );
    }

    public function test_mapped_xlsx_supports_excel_date_serial_and_comma_decimal_normalization(): void
    {
        [, $admin] = $this->organizationWithUsers();

        $account = $this->account(
            $admin,
            'Mapped XLSX'
        );

        $path = $this->createXlsx(
            [
                [
                    'Fecha',
                    'Tipo',
                    'Bruto',
                    'Comision',
                    'Neto',
                    'Operacion',
                ],
                [
                    [
                        'value' =>
                            $this->excelSerial(
                                '2026-08-15 10:30:00'
                            ),
                        'date' => true,
                    ],
                    'C',
                    100.00,
                    2.00,
                    98.00,
                    'XLSX-MAP-1',
                ],
            ]
        );

        $mapping =
            FinancialStatementCsvMapping::fromInput([
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
                'mapping_reference_header' => '',
                'mapping_credit_value' => 'C',
                'mapping_debit_value' => 'D',
            ]);

        $manager = app(
            FinancialStatementCsvImportManager::class
        );

        try {
            $staged = $manager->stageXlsx(
                $account,
                $path,
                'mapped.xlsx',
                $admin,
                $mapping
            );
        } finally {
            @unlink($path);
        }

        $row = $staged['preview']->rows[0];

        $this->assertSame(
            '2026-08-15 13:30:00',
            $row->occurredAt
                ->format('Y-m-d H:i:s')
        );

        $this->assertSame(
            10000,
            $row->grossAmountMinor
        );

        $this->assertSame(
            200,
            $row->feeAmountMinor
        );

        $this->assertSame(
            9800,
            $row->netAmountMinor
        );

        $this->assertStringStartsWith(
            'xlsx:',
            $row->sourceKey
        );

        $manager->commit(
            $staged['token'],
            $admin
        );

        $this->assertDatabaseHas(
            'financial_external_movements',
            [
                'source' =>
                    FinancialMovementSource::Xlsx->value,
                'external_operation_id' =>
                    'XLSX-MAP-1',
            ]
        );
    }

    public function test_same_exact_xlsx_replay_is_idempotent(): void
    {
        [, $admin] = $this->organizationWithUsers();

        $account = $this->account(
            $admin,
            'Replay XLSX'
        );

        $path = $this->createXlsx(
            [
                [
                    'occurred_at',
                    'direction',
                    'gross_amount',
                    'fee_amount',
                    'withholding_amount',
                    'net_amount',
                    'external_operation_id',
                    'reference',
                ],
                [
                    '2026-08-15T10:30:00-03:00',
                    'credit',
                    100.00,
                    0.00,
                    0.00,
                    100.00,
                    '',
                    'Sin ID',
                ],
            ]
        );

        $manager = app(
            FinancialStatementCsvImportManager::class
        );

        try {
            $first = $manager->commit(
                $manager->stageXlsx(
                    $account,
                    $path,
                    'same.xlsx',
                    $admin
                )['token'],
                $admin
            );

            $second = $manager->commit(
                $manager->stageXlsx(
                    $account,
                    $path,
                    'same.xlsx',
                    $admin
                )['token'],
                $admin
            );
        } finally {
            @unlink($path);
        }

        $this->assertSame(
            [
                'total' => 1,
                'created' => 1,
                'deduplicated' => 0,
            ],
            $first
        );

        $this->assertSame(
            [
                'total' => 1,
                'created' => 0,
                'deduplicated' => 1,
            ],
            $second
        );

        $this->assertDatabaseCount(
            'financial_external_movements',
            1
        );
    }

    public function test_xlsx_1904_date_system_is_read_structurally_and_normalized(): void
    {
        [, $admin] = $this->organizationWithUsers();

        $account = $this->account(
            $admin,
            'XLSX 1904'
        );

        $path = $this->createXlsx(
            [
                [
                    'occurred_at',
                    'direction',
                    'gross_amount',
                    'fee_amount',
                    'withholding_amount',
                    'net_amount',
                    'external_operation_id',
                    'reference',
                ],
                [
                    [
                        'value' => 1.5,
                        'date' => true,
                    ],
                    'credit',
                    100.00,
                    0.00,
                    0.00,
                    100.00,
                    'XLSX-1904-1',
                    'Sistema 1904',
                ],
            ],
            true
        );

        try {
            $staged = app(
                FinancialStatementCsvImportManager::class
            )->stageXlsx(
                $account,
                $path,
                'date1904.xlsx',
                $admin
            );
        } finally {
            @unlink($path);
        }

        $row = $staged['preview']->rows[0];

        $this->assertSame(
            '1904-01-02 12:00:00',
            $row->occurredAt->format(
                'Y-m-d H:i:s'
            )
        );

        $this->assertSame(
            'XLSX-1904-1',
            $row->externalOperationId
        );

        $this->assertDatabaseCount(
            'financial_external_movements',
            0
        );
    }

    public function test_xlsx_formula_fails_closed_without_financial_effect(): void
    {
        [, $admin] = $this->organizationWithUsers();

        $account = $this->account(
            $admin,
            'Formula XLSX'
        );

        $path = $this->createXlsx(
            [
                [
                    'occurred_at',
                    'direction',
                    'gross_amount',
                    'fee_amount',
                    'withholding_amount',
                    'net_amount',
                    'external_operation_id',
                    'reference',
                ],
                [
                    '2026-08-15T10:30:00-03:00',
                    'credit',
                    [
                        'value' => 100,
                        'formula' => '50+50',
                    ],
                    0,
                    0,
                    100,
                    'FORMULA-1',
                    'No aceptar fórmula',
                ],
            ]
        );

        $this->expectException(
            DomainException::class
        );

        $this->expectExceptionMessage(
            'P7.4 no acepta fórmulas XLSX'
        );

        try {
            app(
                FinancialStatementCsvImportManager::class
            )->stageXlsx(
                $account,
                $path,
                'formula.xlsx',
                $admin
            );
        } finally {
            @unlink($path);

            $this->assertDatabaseCount(
                'financial_external_movements',
                0
            );
        }
    }

    public function test_cross_source_external_operation_conflict_fails_closed(): void
    {
        [, $admin] = $this->organizationWithUsers();

        $account = $this->account(
            $admin,
            'Cross source'
        );

        $manager = app(
            FinancialStatementCsvImportManager::class
        );

        $csv = implode("\n", [
            'occurred_at,direction,gross_amount,fee_amount,withholding_amount,net_amount,external_operation_id,reference',
            '2026-08-15T10:30:00-03:00,credit,100.00,0.00,0.00,100.00,CROSS-SOURCE-1,CSV',
        ]);

        $csvPath = tempnam(
            sys_get_temp_dir(),
            'srcm-p74-csv-'
        );

        $this->assertNotFalse($csvPath);
        file_put_contents($csvPath, $csv);

        try {
            $manager->commit(
                $manager->stage(
                    $account,
                    $csvPath,
                    'seed.csv',
                    $admin
                )['token'],
                $admin
            );
        } finally {
            @unlink($csvPath);
        }

        $xlsxPath = $this->createXlsx(
            [
                [
                    'occurred_at',
                    'direction',
                    'gross_amount',
                    'fee_amount',
                    'withholding_amount',
                    'net_amount',
                    'external_operation_id',
                    'reference',
                ],
                [
                    '2026-08-15T10:30:00-03:00',
                    'credit',
                    101.00,
                    0.00,
                    0.00,
                    101.00,
                    'CROSS-SOURCE-1',
                    'XLSX conflict',
                ],
            ]
        );

        try {
            $this->expectException(
                DomainException::class
            );

            $this->expectExceptionMessage(
                'ya existe con contenido financiero diferente'
            );

            $manager->commit(
                $manager->stageXlsx(
                    $account,
                    $xlsxPath,
                    'conflict.xlsx',
                    $admin
                )['token'],
                $admin
            );
        } finally {
            @unlink($xlsxPath);
        }
    }

    /**
     * @param list<list<mixed>> $rows
     */
    private function createXlsx(
        array $rows,
        bool $date1904 = false
    ): string {
        $path = tempnam(
            sys_get_temp_dir(),
            'srcm-p74-xlsx-'
        );

        $this->assertNotFalse($path);

        $zip = new ZipArchive();

        $this->assertTrue(
            $zip->open(
                $path,
                ZipArchive::CREATE
                    | ZipArchive::OVERWRITE
            ) === true
        );

        $zip->addFromString(
            '[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>'
        );

        $zip->addFromString(
            '_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>'
        );

        $zip->addFromString(
            'xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<workbookPr date1904="'
            .($date1904 ? '1' : '0')
            .'"/>'
            .'<sheets><sheet name="Extracto" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>'
        );

        $zip->addFromString(
            'xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>'
        );

        $zip->addFromString(
            'xl/styles.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="1"><numFmt numFmtId="164" formatCode="dd/mm/yyyy hh:mm:ss"/></numFmts>'
            .'<fonts count="1"><font/></fonts>'
            .'<fills count="1"><fill/></fills>'
            .'<borders count="1"><border/></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0"/></cellStyleXfs>'
            .'<cellXfs count="2"><xf numFmtId="0"/><xf numFmtId="164" applyNumberFormat="1"/></cellXfs>'
            .'</styleSheet>'
        );

        $sheetRows = [];

        foreach ($rows as $rowIndex => $row) {
            $cells = [];

            foreach ($row as $columnIndex => $value) {
                $reference =
                    $this->columnLetters(
                        $columnIndex + 1
                    ).($rowIndex + 1);

                $cells[] = $this->xlsxCell(
                    $reference,
                    $value
                );
            }

            $sheetRows[] =
                '<row r="'.($rowIndex + 1).'">'
                .implode('', $cells)
                .'</row>';
        }

        $zip->addFromString(
            'xl/worksheets/sheet1.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetData>'
            .implode('', $sheetRows)
            .'</sheetData>'
            .'</worksheet>'
        );

        $zip->close();

        return $path;
    }

    private function xlsxCell(
        string $reference,
        mixed $value
    ): string {
        $style = '';
        $formula = null;

        if (is_array($value)) {
            $style = ! empty($value['date'])
                ? ' s="1"'
                : '';

            $formula =
                $value['formula'] ?? null;

            $value =
                $value['value'] ?? '';
        }

        if (is_int($value) || is_float($value)) {
            return '<c r="'.$reference.'"'.$style.'>'
                .($formula !== null
                    ? '<f>'.$this->xml(
                        (string) $formula
                    ).'</f>'
                    : '')
                .'<v>'.$this->xml(
                    (string) $value
                ).'</v>'
                .'</c>';
        }

        return '<c r="'.$reference.'" t="inlineStr"'.$style.'>'
            .'<is><t>'.$this->xml(
                (string) $value
            ).'</t></is>'
            .'</c>';
    }

    private function columnLetters(
        int $index
    ): string {
        $letters = '';

        while ($index > 0) {
            $index--;
            $letters =
                chr(65 + ($index % 26))
                .$letters;
            $index = intdiv($index, 26);
        }

        return $letters;
    }

    private function xml(
        string $value
    ): string {
        return htmlspecialchars(
            $value,
            ENT_XML1 | ENT_QUOTES,
            'UTF-8'
        );
    }

    private function excelSerial(
        string $localDate
    ): float {
        $base = new \DateTimeImmutable(
            '1899-12-30 00:00:00',
            new \DateTimeZone('UTC')
        );

        $date = new \DateTimeImmutable(
            $localDate,
            new \DateTimeZone('UTC')
        );

        return (
            $date->getTimestamp()
            - $base->getTimestamp()
        ) / 86400;
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
                'name' => 'Org P7.4 '.$suffix,
                'slug' => 'org-p74-'.$suffix,
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
