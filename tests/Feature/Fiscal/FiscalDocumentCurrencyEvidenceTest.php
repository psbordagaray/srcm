<?php

namespace Tests\Feature\Fiscal;

use App\Domain\Fiscal\FiscalDocumentCurrencyEvidenceData;
use App\Domain\Fiscal\FiscalDocumentCurrencyEvidenceManager;
use App\Domain\Fiscal\FiscalOrganizationProfileData;
use App\Domain\Fiscal\FiscalOrganizationProfileManager;
use App\Domain\Fiscal\FiscalPointOfSaleData;
use App\Domain\Fiscal\FiscalPointOfSaleManager;
use App\Enums\FiscalDocumentType;
use App\Enums\FiscalEnvironment;
use App\Enums\FiscalIntegrationMode;
use App\Enums\UserRole;
use App\Models\CommerceSale;
use App\Models\FiscalAuthorizationAttempt;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentCurrencyEvidence;
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

class FiscalDocumentCurrencyEvidenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_schema_and_document_relation_are_explicit(): void
    {
        $this->assertTrue(Schema::hasColumns('fiscal_document_currency_evidence', [
            'organization_id',
            'fiscal_document_id',
            'source_currency_code',
            'arca_currency_code',
            'quotation_micros',
            'same_currency_settlement',
            'recorded_at',
            'recorded_by_user_id',
        ]));

        [$admin, $document] = $this->documentFixture('ARS');

        $this->assertSame('ARS', $document->currency_code);
        $this->assertNull($document->currencyEvidence()->first());
    }

    public function test_peso_evidence_is_explicit_idempotent_and_keeps_source_code_separate(): void
    {
        [$admin, $document] = $this->documentFixture('ARS');

        $manager = app(FiscalDocumentCurrencyEvidenceManager::class);
        $data = new FiscalDocumentCurrencyEvidenceData(
            $document->id,
            'ARS',
            'PES',
            1_000_000,
            false
        );

        $evidence = $manager->record($data, $admin);
        $again = $manager->record($data, $admin);

        $this->assertSame($evidence->id, $again->id);
        $this->assertSame('ARS', $evidence->source_currency_code);
        $this->assertSame('PES', $evidence->arca_currency_code);
        $this->assertSame(1_000_000, $evidence->quotation_micros);
        $this->assertFalse($evidence->same_currency_settlement);
        $this->assertSame('ARS', $document->currency_code);
        $this->assertSame(1, FiscalDocumentCurrencyEvidence::query()->count());
    }

    public function test_source_currency_must_match_immutable_document_currency(): void
    {
        [$admin, $document] = $this->documentFixture('ARS');

        $this->expectException(DomainException::class);

        app(FiscalDocumentCurrencyEvidenceManager::class)->record(
            new FiscalDocumentCurrencyEvidenceData(
                $document->id,
                'USD',
                'DOL',
                1_200_000,
                false
            ),
            $admin
        );
    }

    public function test_pes_requires_exact_quotation_one(): void
    {
        [$admin, $document] = $this->documentFixture('ARS');

        $this->expectException(DomainException::class);

        app(FiscalDocumentCurrencyEvidenceManager::class)->record(
            new FiscalDocumentCurrencyEvidenceData(
                $document->id,
                'ARS',
                'PES',
                999_999,
                false
            ),
            $admin
        );
    }

    public function test_pes_cannot_be_marked_as_same_foreign_currency_settlement(): void
    {
        [$admin, $document] = $this->documentFixture('ARS');

        $this->expectException(DomainException::class);

        app(FiscalDocumentCurrencyEvidenceManager::class)->record(
            new FiscalDocumentCurrencyEvidenceData(
                $document->id,
                'ARS',
                'PES',
                1_000_000,
                true
            ),
            $admin
        );
    }

    public function test_foreign_currency_preserves_explicit_quote_and_settlement_choice(): void
    {
        [$admin, $document] = $this->documentFixture('USD');

        $evidence = app(FiscalDocumentCurrencyEvidenceManager::class)->record(
            new FiscalDocumentCurrencyEvidenceData(
                $document->id,
                'USD',
                'DOL',
                1_234_567,
                true
            ),
            $admin
        );

        $this->assertSame('USD', $evidence->source_currency_code);
        $this->assertSame('DOL', $evidence->arca_currency_code);
        $this->assertSame(1_234_567, $evidence->quotation_micros);
        $this->assertTrue($evidence->same_currency_settlement);
    }

    public function test_quotation_precision_range_fails_closed(): void
    {
        [$admin, $document] = $this->documentFixture('USD');

        $this->expectException(DomainException::class);

        app(FiscalDocumentCurrencyEvidenceManager::class)->record(
            new FiscalDocumentCurrencyEvidenceData(
                $document->id,
                'USD',
                'DOL',
                10_000_000_000,
                false
            ),
            $admin
        );
    }

    public function test_conflicting_second_evidence_fails_closed(): void
    {
        [$admin, $document] = $this->documentFixture('USD');

        $manager = app(FiscalDocumentCurrencyEvidenceManager::class);

        $manager->record(
            new FiscalDocumentCurrencyEvidenceData(
                $document->id,
                'USD',
                'DOL',
                1_234_567,
                false
            ),
            $admin
        );

        $this->expectException(DomainException::class);

        $manager->record(
            new FiscalDocumentCurrencyEvidenceData(
                $document->id,
                'USD',
                'DOL',
                1_234_568,
                false
            ),
            $admin
        );
    }

    public function test_evidence_cannot_be_added_after_authorization_attempt(): void
    {
        [$admin, $document] = $this->documentFixture('ARS');

        FiscalAuthorizationAttempt::query()->create([
            'organization_id' => $admin->current_organization_id,
            'fiscal_document_id' => $document->id,
            'attempt_number' => 1,
            'requested_at' => now(),
            'recorded_by_user_id' => $admin->id,
            'idempotency_key' => 'currency-evidence-attempt',
            'fingerprint' => str_repeat('a', 64),
        ]);

        $this->expectException(DomainException::class);

        app(FiscalDocumentCurrencyEvidenceManager::class)->record(
            new FiscalDocumentCurrencyEvidenceData(
                $document->id,
                'ARS',
                'PES',
                1_000_000,
                false
            ),
            $admin
        );
    }

    public function test_model_and_database_preserve_currency_evidence_immutability(): void
    {
        [$admin, $document] = $this->documentFixture('ARS');

        $evidence = app(FiscalDocumentCurrencyEvidenceManager::class)->record(
            new FiscalDocumentCurrencyEvidenceData(
                $document->id,
                'ARS',
                'PES',
                1_000_000,
                false
            ),
            $admin
        );

        try {
            $evidence->update(['quotation_micros' => 1_000_001]);
            $this->fail('Se esperaba inmutabilidad de modelo.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        try {
            DB::table('fiscal_document_currency_evidence')
                ->where('id', $evidence->id)
                ->update(['quotation_micros' => 1_000_001]);

            $this->fail('Se esperaba inmutabilidad de base de datos.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_only_admin_can_record_currency_evidence(): void
    {
        [$admin, $document] = $this->documentFixture('ARS');

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

        app(FiscalDocumentCurrencyEvidenceManager::class)->record(
            new FiscalDocumentCurrencyEvidenceData(
                $document->id,
                'ARS',
                'PES',
                1_000_000,
                false
            ),
            $viewer
        );
    }

    /** @return array{User,FiscalDocument} */
    private function documentFixture(string $currencyCode): array
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
            'sale_number' => $currencyCode === 'ARS' ? 9201 : 9202,
            'status' => 'building',
            'customer_name_snapshot' => 'Receptor Fiscal',
            'currency_code' => $currencyCode,
            'service_subtotal_minor' => 0,
            'product_subtotal_minor' => 1210,
            'total_minor' => 1210,
            'recorded_by_user_id' => $admin->id,
            'sold_at' => now(),
            'idempotency_key' => 'currency-evidence-sale-'.$currencyCode,
            'fingerprint' => hash('sha256', 'currency-evidence-sale-'.$currencyCode),
        ]);

        $document = FiscalDocument::query()->create([
            'organization_id' => $organization->id,
            'fiscal_organization_profile_id' => $point->fiscal_organization_profile_id,
            'fiscal_point_of_sale_id' => $point->id,
            'commerce_sale_id' => $sale->id,
            'document_type' => FiscalDocumentType::Invoice,
            'issuer_snapshot' => ['legal_name' => 'Empresa Fiscal'],
            'recipient_snapshot' => ['name' => 'Receptor Fiscal'],
            'currency_code' => $currencyCode,
            'service_subtotal_minor' => 0,
            'product_subtotal_minor' => 1210,
            'total_minor' => 1210,
            'documented_at' => now(),
            'created_by_user_id' => $admin->id,
            'idempotency_key' => 'currency-evidence-document-'.$currencyCode,
            'fingerprint' => hash('sha256', 'currency-evidence-document-'.$currencyCode),
        ]);

        return [$admin, $document];
    }
}
