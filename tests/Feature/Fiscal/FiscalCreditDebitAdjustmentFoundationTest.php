<?php

namespace Tests\Feature\Fiscal;

use App\Domain\Fiscal\FiscalAdjustmentData;
use App\Domain\Fiscal\FiscalAdjustmentLineData;
use App\Domain\Fiscal\FiscalAdjustmentManager;
use App\Domain\Fiscal\FiscalAuthorizationFactData;
use App\Domain\Fiscal\FiscalAuthorizationFactManager;
use App\Domain\Fiscal\FiscalOrganizationProfileData;
use App\Domain\Fiscal\FiscalOrganizationProfileManager;
use App\Domain\Fiscal\FiscalPointOfSaleData;
use App\Domain\Fiscal\FiscalPointOfSaleManager;
use App\Enums\CommerceSaleLineType;
use App\Enums\FiscalAuthorizationOutcome;
use App\Enums\FiscalDocumentType;
use App\Enums\FiscalEnvironment;
use App\Enums\FiscalIntegrationMode;
use App\Enums\UserRole;
use App\Models\CommerceSale;
use App\Models\FiscalAuthorizationAttempt;
use App\Models\FiscalDocument;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FiscalCreditDebitAdjustmentFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_sqlite_rebuild_preserves_existing_fiscal_dependency_triggers(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Este gate protege específicamente el rebuild SQLite.');
        }

        $triggerNames = collect(
            DB::select(
                "SELECT name FROM sqlite_master WHERE type = 'trigger' ORDER BY name"
            )
        )->pluck('name')->all();

        foreach ([
            'fiscal_documents_immutable_update',
            'fiscal_documents_immutable_delete',
            'fiscal_document_lines_immutable_update',
            'fiscal_document_lines_immutable_delete',
            'fiscal_document_recipient_evidence_tenant_insert',
            'fiscal_document_issue_dates_tenant_insert',
            'fiscal_document_monetary_summaries_tenant_insert',
            'fiscal_document_currency_evidence_tenant_insert',
            'fiscal_document_payment_due_dates_tenant_insert',
            'fiscal_documents_origin_guard_insert',
            'fiscal_document_lines_origin_guard_insert',
            'fiscal_adjustment_authorization_association_gate_insert',
        ] as $triggerName) {
            $this->assertContains($triggerName, $triggerNames);
        }
    }

    public function test_schema_allows_fiscal_adjustment_without_commerce_origin(): void
    {
        [$admin, $point] = $this->fiscalConfiguration();

        $document = app(FiscalAdjustmentManager::class)->record(
            $this->adjustmentData(
                $point->id,
                FiscalDocumentType::CreditNote,
                'adjustment:schema'
            ),
            $admin
        );

        $this->assertNull($document->commerce_sale_id);
        $this->assertTrue($document->lines->every(
            fn ($line) => $line->commerce_sale_line_id === null
        ));
        $this->assertTrue(Schema::hasColumn('fiscal_documents', 'commerce_sale_id'));
        $this->assertTrue(Schema::hasColumn('fiscal_document_lines', 'commerce_sale_line_id'));
    }

    public function test_credit_note_is_created_from_explicit_fiscal_data_and_is_idempotent(): void
    {
        [$admin, $point] = $this->fiscalConfiguration();

        $data = $this->adjustmentData(
            $point->id,
            FiscalDocumentType::CreditNote,
            'adjustment:credit'
        );

        $manager = app(FiscalAdjustmentManager::class);

        $document = $manager->record($data, $admin);
        $again = $manager->record($data, $admin);

        $this->assertSame($document->id, $again->id);
        $this->assertSame(FiscalDocumentType::CreditNote, $document->document_type);
        $this->assertNull($document->commerce_sale_id);
        $this->assertSame('ARS', $document->currency_code);
        $this->assertSame(1000, $document->service_subtotal_minor);
        $this->assertSame(2100, $document->product_subtotal_minor);
        $this->assertSame(3100, $document->total_minor);
        $this->assertSame('Receptor Ajuste', $document->recipient_snapshot['name']);
        $this->assertCount(2, $document->lines);
        $this->assertSame('Servicio explícito', $document->lines[0]->description);
        $this->assertSame('Producto explícito', $document->lines[1]->description);
        $this->assertSame(1, FiscalDocument::query()->where('idempotency_key', 'adjustment:credit')->count());
    }

    public function test_debit_note_is_supported_but_invoice_is_not(): void
    {
        [$admin, $point] = $this->fiscalConfiguration();

        $debit = app(FiscalAdjustmentManager::class)->record(
            $this->adjustmentData(
                $point->id,
                FiscalDocumentType::DebitNote,
                'adjustment:debit'
            ),
            $admin
        );

        $this->assertSame(FiscalDocumentType::DebitNote, $debit->document_type);

        $this->expectException(DomainException::class);

        app(FiscalAdjustmentManager::class)->record(
            $this->adjustmentData(
                $point->id,
                FiscalDocumentType::Invoice,
                'adjustment:invoice-forbidden'
            ),
            $admin
        );
    }

    public function test_line_sums_must_match_explicit_subtotals_and_total(): void
    {
        [$admin, $point] = $this->fiscalConfiguration();

        $data = new FiscalAdjustmentData(
            $point->id,
            FiscalDocumentType::CreditNote,
            ['name' => 'Receptor Ajuste'],
            'ARS',
            1000,
            2000,
            3000,
            [
                new FiscalAdjustmentLineData(
                    1,
                    CommerceSaleLineType::Service,
                    'Servicio',
                    '1.000000',
                    1000,
                    1000
                ),
                new FiscalAdjustmentLineData(
                    2,
                    CommerceSaleLineType::Product,
                    'Producto',
                    '1',
                    2100,
                    2100
                ),
            ],
            'adjustment:bad-sums'
        );

        $this->expectException(DomainException::class);

        app(FiscalAdjustmentManager::class)->record(
            $data,
            $admin
        );
    }

    public function test_positions_quantity_currency_and_recipient_are_explicit_fail_closed_inputs(): void
    {
        [$admin, $point] = $this->fiscalConfiguration();

        $data = new FiscalAdjustmentData(
            $point->id,
            FiscalDocumentType::CreditNote,
            [],
            'ars',
            1000,
            0,
            1000,
            [
                new FiscalAdjustmentLineData(
                    2,
                    CommerceSaleLineType::Service,
                    '',
                    '0',
                    1000,
                    1000
                ),
            ],
            'adjustment:bad-input'
        );

        $this->expectException(DomainException::class);

        app(FiscalAdjustmentManager::class)->record(
            $data,
            $admin
        );
    }

    public function test_conflicting_idempotency_key_fails_closed(): void
    {
        [$admin, $point] = $this->fiscalConfiguration();
        $manager = app(FiscalAdjustmentManager::class);

        $manager->record(
            $this->adjustmentData(
                $point->id,
                FiscalDocumentType::CreditNote,
                'adjustment:conflict'
            ),
            $admin
        );

        $conflict = new FiscalAdjustmentData(
            $point->id,
            FiscalDocumentType::CreditNote,
            ['name' => 'Otro Receptor'],
            'ARS',
            1000,
            2100,
            3100,
            [
                new FiscalAdjustmentLineData(
                    1,
                    CommerceSaleLineType::Service,
                    'Servicio explícito',
                    '1',
                    1000,
                    1000
                ),
                new FiscalAdjustmentLineData(
                    2,
                    CommerceSaleLineType::Product,
                    'Producto explícito',
                    '1',
                    2100,
                    2100
                ),
            ],
            'adjustment:conflict'
        );

        $this->expectException(DomainException::class);

        $manager->record($conflict, $admin);
    }

    public function test_database_preserves_invoice_and_adjustment_origin_boundaries(): void
    {
        [$admin, $point] = $this->fiscalConfiguration();

        DB::unprepared('DROP TRIGGER IF EXISTS commerce_sales_guard_insert');

        $sale = CommerceSale::query()->create([
            'organization_id' => $admin->current_organization_id,
            'sale_number' => 9401,
            'status' => 'building',
            'customer_name_snapshot' => 'Origen comercial',
            'currency_code' => 'ARS',
            'service_subtotal_minor' => 0,
            'product_subtotal_minor' => 100,
            'total_minor' => 100,
            'recorded_by_user_id' => $admin->id,
            'sold_at' => now(),
            'idempotency_key' => 'adjustment-origin-sale',
            'fingerprint' => str_repeat('a', 64),
        ]);

        try {
            DB::table('fiscal_documents')->insert([
                'organization_id' => $admin->current_organization_id,
                'public_id' => (string) \Illuminate\Support\Str::uuid(),
                'fiscal_organization_profile_id' => $point->fiscal_organization_profile_id,
                'fiscal_point_of_sale_id' => $point->id,
                'commerce_sale_id' => null,
                'document_type' => 'invoice',
                'issuer_snapshot' => json_encode(['legal_name' => 'Empresa Fiscal'], JSON_THROW_ON_ERROR),
                'recipient_snapshot' => json_encode(['name' => 'Receptor'], JSON_THROW_ON_ERROR),
                'currency_code' => 'ARS',
                'service_subtotal_minor' => 0,
                'product_subtotal_minor' => 100,
                'total_minor' => 100,
                'documented_at' => now(),
                'created_by_user_id' => $admin->id,
                'idempotency_key' => 'db:invoice-null-sale',
                'fingerprint' => str_repeat('b', 64),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->fail('La factura sin venta debió ser rechazada por DB.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(QueryException::class);

        DB::table('fiscal_documents')->insert([
            'organization_id' => $admin->current_organization_id,
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'fiscal_organization_profile_id' => $point->fiscal_organization_profile_id,
            'fiscal_point_of_sale_id' => $point->id,
            'commerce_sale_id' => $sale->id,
            'document_type' => 'credit_note',
            'issuer_snapshot' => json_encode(['legal_name' => 'Empresa Fiscal'], JSON_THROW_ON_ERROR),
            'recipient_snapshot' => json_encode(['name' => 'Receptor'], JSON_THROW_ON_ERROR),
            'currency_code' => 'ARS',
            'service_subtotal_minor' => 0,
            'product_subtotal_minor' => 100,
            'total_minor' => 100,
            'documented_at' => now(),
            'created_by_user_id' => $admin->id,
            'idempotency_key' => 'db:note-with-sale',
            'fingerprint' => str_repeat('c', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_adjustment_authorization_is_blocked_until_association_evidence_exists(): void
    {
        [$admin, $point] = $this->fiscalConfiguration();

        $document = app(FiscalAdjustmentManager::class)->record(
            $this->adjustmentData(
                $point->id,
                FiscalDocumentType::CreditNote,
                'adjustment:auth-block'
            ),
            $admin
        );

        try {
            app(FiscalAuthorizationFactManager::class)->record(
                new FiscalAuthorizationFactData(
                    $document->id,
                    FiscalAuthorizationOutcome::Unknown,
                    null,
                    'adjustment:auth-fact'
                ),
                $admin
            );

            $this->fail('La autorización de la nota debió quedar bloqueada.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(
            0,
            FiscalAuthorizationAttempt::query()
                ->where('fiscal_document_id', $document->id)
                ->count()
        );

        $this->expectException(QueryException::class);

        DB::table('fiscal_authorization_attempts')->insert([
            'organization_id' => $admin->current_organization_id,
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'fiscal_document_id' => $document->id,
            'attempt_number' => 1,
            'requested_at' => now(),
            'recorded_by_user_id' => $admin->id,
            'idempotency_key' => 'adjustment:db-auth-bypass',
            'fingerprint' => str_repeat('d', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_adjustment_document_and_lines_remain_immutable(): void
    {
        [$admin, $point] = $this->fiscalConfiguration();

        $document = app(FiscalAdjustmentManager::class)->record(
            $this->adjustmentData(
                $point->id,
                FiscalDocumentType::DebitNote,
                'adjustment:immutable'
            ),
            $admin
        );

        try {
            DB::table('fiscal_documents')
                ->where('id', $document->id)
                ->update(['total_minor' => 1]);

            $this->fail('El documento fiscal debió permanecer inmutable.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(QueryException::class);

        DB::table('fiscal_document_lines')
            ->where('fiscal_document_id', $document->id)
            ->where('position', 1)
            ->update(['line_total_minor' => 1]);
    }

    public function test_only_admin_can_create_adjustment(): void
    {
        [$admin, $point] = $this->fiscalConfiguration();

        $viewer = User::factory()->create([
            'role' => UserRole::Viewer,
            'current_organization_id' => $admin->current_organization_id,
        ]);

        OrganizationMembership::withoutEvents(
            fn () => OrganizationMembership::query()->updateOrCreate(
                [
                    'organization_id' => $admin->current_organization_id,
                    'user_id' => $viewer->id,
                ],
                [
                    'role' => UserRole::Viewer,
                    'active' => true,
                ]
            )
        );

        $this->expectException(DomainException::class);

        app(FiscalAdjustmentManager::class)->record(
            $this->adjustmentData(
                $point->id,
                FiscalDocumentType::CreditNote,
                'adjustment:viewer'
            ),
            $viewer
        );
    }

    private function adjustmentData(
        int $pointId,
        FiscalDocumentType $type,
        string $idempotencyKey
    ): FiscalAdjustmentData {
        return new FiscalAdjustmentData(
            $pointId,
            $type,
            [
                'name' => 'Receptor Ajuste',
                'document' => 'DNI 12.345.678',
            ],
            'ARS',
            1000,
            2100,
            3100,
            [
                new FiscalAdjustmentLineData(
                    1,
                    CommerceSaleLineType::Service,
                    'Servicio explícito',
                    '1.000000',
                    1000,
                    1000
                ),
                new FiscalAdjustmentLineData(
                    2,
                    CommerceSaleLineType::Product,
                    'Producto explícito',
                    '1',
                    2100,
                    2100
                ),
            ],
            $idempotencyKey
        );
    }

    private function fiscalConfiguration(): array
    {
        $organization = Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();

        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'current_organization_id' => $organization->id,
        ]);

        OrganizationMembership::withoutEvents(
            fn () => OrganizationMembership::query()->updateOrCreate(
                [
                    'organization_id' => $organization->id,
                    'user_id' => $admin->id,
                ],
                [
                    'role' => UserRole::Admin,
                    'active' => true,
                ]
            )
        );

        app(FiscalOrganizationProfileManager::class)->save(
            new FiscalOrganizationProfileData(
                'Empresa Fiscal',
                '20-12345678-6',
                '1',
                null,
                '2020-01-01',
                'Calle 1',
                'Córdoba',
                'AR-C',
                '5000'
            ),
            $admin
        );

        $point = app(FiscalPointOfSaleManager::class)->create(
            new FiscalPointOfSaleData(
                1,
                FiscalEnvironment::Homologation,
                FiscalIntegrationMode::WsfeV1
            ),
            $admin
        );

        return [$admin, $point];
    }
}
