<?php

namespace Tests\Feature\Fiscal;

use App\Domain\Fiscal\FiscalDocumentMonetarySummaryData;
use App\Domain\Fiscal\FiscalDocumentMonetarySummaryManager;
use App\Domain\Fiscal\FiscalOrganizationProfileData;
use App\Domain\Fiscal\FiscalOrganizationProfileManager;
use App\Domain\Fiscal\FiscalPointOfSaleData;
use App\Domain\Fiscal\FiscalPointOfSaleManager;
use App\Domain\Fiscal\FiscalTaxCompositionData;
use App\Domain\Fiscal\FiscalTaxCompositionManager;
use App\Enums\FiscalDocumentType;
use App\Enums\FiscalEnvironment;
use App\Enums\FiscalIntegrationMode;
use App\Enums\UserRole;
use App\Models\CommerceSale;
use App\Models\FiscalAuthorizationAttempt;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentMonetarySummary;
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

class FiscalDocumentMonetarySummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_schema_and_document_relation_are_explicit_and_do_not_duplicate_total(): void
    {
        $this->assertTrue(Schema::hasColumns('fiscal_document_monetary_summaries', [
            'organization_id',
            'fiscal_document_id',
            'non_taxed_amount_minor',
            'net_taxable_amount_minor',
            'exempt_amount_minor',
            'tributes_amount_minor',
            'vat_amount_minor',
            'recorded_at',
            'recorded_by_user_id',
        ]));
        $this->assertFalse(Schema::hasColumn(
            'fiscal_document_monetary_summaries',
            'total_amount_minor'
        ));

        [$admin, $document] = $this->documentFixture();

        $this->assertNull($document->monetarySummary()->first());
        $this->assertSame(1210, $document->total_minor);
    }

    public function test_summary_is_explicit_idempotent_and_matches_document_total(): void
    {
        [$admin, $document] = $this->documentFixture();
        $this->taxComposition($admin, $document, 210);

        $manager = app(FiscalDocumentMonetarySummaryManager::class);
        $data = $this->summaryData($document);

        $summary = $manager->record($data, $admin);
        $again = $manager->record($data, $admin);

        $this->assertSame($summary->id, $again->id);
        $this->assertSame(0, $summary->non_taxed_amount_minor);
        $this->assertSame(1000, $summary->net_taxable_amount_minor);
        $this->assertSame(0, $summary->exempt_amount_minor);
        $this->assertSame(0, $summary->tributes_amount_minor);
        $this->assertSame(210, $summary->vat_amount_minor);
        $this->assertSame(1210, $document->total_minor);
        $this->assertSame(1, FiscalDocumentMonetarySummary::query()->count());
    }

    public function test_summary_requires_explicit_tax_composition_first(): void
    {
        [$admin, $document] = $this->documentFixture();

        $this->expectException(DomainException::class);

        app(FiscalDocumentMonetarySummaryManager::class)->record(
            $this->summaryData($document),
            $admin
        );
    }

    public function test_summary_arithmetic_must_match_immutable_document_total(): void
    {
        [$admin, $document] = $this->documentFixture();
        $this->taxComposition($admin, $document, 200);

        $this->expectException(DomainException::class);

        app(FiscalDocumentMonetarySummaryManager::class)->record(
            new FiscalDocumentMonetarySummaryData(
                $document->id,
                0,
                1000,
                0,
                0,
                200
            ),
            $admin
        );
    }

    public function test_summary_tax_total_must_match_explicit_tax_composition(): void
    {
        [$admin, $document] = $this->documentFixture();
        $this->taxComposition($admin, $document, 200);

        $this->expectException(DomainException::class);

        app(FiscalDocumentMonetarySummaryManager::class)->record(
            $this->summaryData($document),
            $admin
        );
    }

    public function test_conflicting_second_summary_fails_closed(): void
    {
        [$admin, $document] = $this->documentFixture();
        $this->taxComposition($admin, $document, 210);

        $manager = app(FiscalDocumentMonetarySummaryManager::class);
        $manager->record($this->summaryData($document), $admin);

        $this->expectException(DomainException::class);

        $manager->record(
            new FiscalDocumentMonetarySummaryData(
                $document->id,
                10,
                990,
                0,
                0,
                210
            ),
            $admin
        );
    }

    public function test_summary_cannot_be_added_after_authorization_attempt(): void
    {
        [$admin, $document] = $this->documentFixture();
        $this->taxComposition($admin, $document, 210);

        FiscalAuthorizationAttempt::query()->create([
            'organization_id' => $admin->current_organization_id,
            'fiscal_document_id' => $document->id,
            'attempt_number' => 1,
            'requested_at' => now(),
            'recorded_by_user_id' => $admin->id,
            'idempotency_key' => 'monetary-summary-attempt',
            'fingerprint' => str_repeat('a', 64),
        ]);

        $this->expectException(DomainException::class);

        app(FiscalDocumentMonetarySummaryManager::class)->record(
            $this->summaryData($document),
            $admin
        );
    }

    public function test_model_and_database_preserve_summary_immutability(): void
    {
        [$admin, $document] = $this->documentFixture();
        $this->taxComposition($admin, $document, 210);

        $summary = app(FiscalDocumentMonetarySummaryManager::class)->record(
            $this->summaryData($document),
            $admin
        );

        try {
            $summary->update(['vat_amount_minor' => 211]);
            $this->fail('Se esperaba inmutabilidad de modelo.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        try {
            DB::table('fiscal_document_monetary_summaries')
                ->where('id', $summary->id)
                ->update(['vat_amount_minor' => 211]);
            $this->fail('Se esperaba inmutabilidad de base de datos.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_only_admin_can_record_monetary_summary(): void
    {
        [$admin, $document] = $this->documentFixture();
        $this->taxComposition($admin, $document, 210);

        $viewer = User::factory()->create([
            'role' => UserRole::Viewer,
            'current_organization_id' => $admin->current_organization_id,
        ]);

        OrganizationMembership::withoutEvents(fn () => OrganizationMembership::query()->updateOrCreate(
            [
                'organization_id' => $admin->current_organization_id,
                'user_id' => $viewer->id,
            ],
            [
                'role' => UserRole::Viewer,
                'active' => true,
            ]
        ));

        $this->expectException(DomainException::class);

        app(FiscalDocumentMonetarySummaryManager::class)->record(
            $this->summaryData($document),
            $viewer
        );
    }

    private function summaryData(FiscalDocument $document): FiscalDocumentMonetarySummaryData
    {
        return new FiscalDocumentMonetarySummaryData(
            $document->id,
            0,
            1000,
            0,
            0,
            210
        );
    }

    private function taxComposition(
        User $admin,
        FiscalDocument $document,
        int $taxAmountMinor
    ): void {
        app(FiscalTaxCompositionManager::class)->record(
            new FiscalTaxCompositionData(
                $document->id,
                [[
                    'tax_code' => 'IVA_21',
                    'taxable_base_minor' => 1000,
                    'rate_basis_points' => 2100,
                    'tax_amount_minor' => $taxAmountMinor,
                ]],
                'monetary-summary-tax-'.$taxAmountMinor
            ),
            $admin
        );
    }

    /** @return array{User,FiscalDocument} */
    private function documentFixture(): array
    {
        $organization = Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();

        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'current_organization_id' => $organization->id,
        ]);

        OrganizationMembership::withoutEvents(fn () => OrganizationMembership::query()->updateOrCreate(
            [
                'organization_id' => $organization->id,
                'user_id' => $admin->id,
            ],
            [
                'role' => UserRole::Admin,
                'active' => true,
            ]
        ));

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

        DB::unprepared('DROP TRIGGER IF EXISTS commerce_sales_guard_insert');

        $sale = CommerceSale::query()->create([
            'organization_id' => $organization->id,
            'sale_number' => 9101,
            'status' => 'building',
            'customer_name_snapshot' => 'Receptor Fiscal',
            'currency_code' => 'ARS',
            'service_subtotal_minor' => 0,
            'product_subtotal_minor' => 1210,
            'total_minor' => 1210,
            'recorded_by_user_id' => $admin->id,
            'sold_at' => now(),
            'idempotency_key' => 'monetary-summary-sale',
            'fingerprint' => str_repeat('b', 64),
        ]);

        $document = FiscalDocument::query()->create([
            'organization_id' => $organization->id,
            'fiscal_organization_profile_id' => $point->fiscal_organization_profile_id,
            'fiscal_point_of_sale_id' => $point->id,
            'commerce_sale_id' => $sale->id,
            'document_type' => FiscalDocumentType::Invoice,
            'issuer_snapshot' => ['legal_name' => 'Empresa Fiscal'],
            'recipient_snapshot' => ['name' => 'Receptor Fiscal'],
            'currency_code' => 'ARS',
            'service_subtotal_minor' => 0,
            'product_subtotal_minor' => 1210,
            'total_minor' => 1210,
            'documented_at' => now(),
            'created_by_user_id' => $admin->id,
            'idempotency_key' => 'monetary-summary-document',
            'fingerprint' => str_repeat('c', 64),
        ]);

        return [$admin, $document];
    }
}
