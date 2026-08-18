<?php

namespace Tests\Feature\Fiscal;

use App\Domain\Fiscal\FiscalDocumentRecipientEvidenceData;
use App\Domain\Fiscal\FiscalDocumentRecipientEvidenceManager;
use App\Domain\Fiscal\FiscalOrganizationProfileData;
use App\Domain\Fiscal\FiscalOrganizationProfileManager;
use App\Domain\Fiscal\FiscalPointOfSaleData;
use App\Domain\Fiscal\FiscalPointOfSaleManager;
use App\Enums\FiscalDocumentType;
use App\Enums\FiscalEnvironment;
use App\Enums\FiscalIntegrationMode;
use App\Enums\UserRole;
use App\Models\BusinessParty;
use App\Models\CommerceSale;
use App\Models\FiscalAuthorizationAttempt;
use App\Models\FiscalBusinessPartyProfile;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentRecipientEvidence;
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

class FiscalDocumentRecipientEvidenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_schema_and_document_relation_are_explicit(): void
    {
        $this->assertTrue(Schema::hasColumns('fiscal_document_recipient_evidence', [
            'organization_id',
            'fiscal_document_id',
            'document_type_code',
            'document_number',
            'vat_condition_code',
            'recorded_at',
            'recorded_by_user_id',
        ]));

        [$admin, $document] = $this->documentFixture();

        $this->assertNull($document->recipientEvidence()->first());
        $this->assertSame('DNI 12.345.678', $document->recipient_snapshot['document']);
    }

    public function test_recipient_evidence_is_explicit_idempotent_and_does_not_rewrite_commercial_snapshot(): void
    {
        [$admin, $document] = $this->documentFixture();
        $manager = app(FiscalDocumentRecipientEvidenceManager::class);
        $data = new FiscalDocumentRecipientEvidenceData(
            $document->id,
            '80',
            '20-11111111-9',
            '1'
        );

        $evidence = $manager->record($data, $admin);
        $again = $manager->record($data, $admin);

        $this->assertSame($evidence->id, $again->id);
        $this->assertSame('80', $evidence->document_type_code);
        $this->assertSame('20111111119', $evidence->document_number);
        $this->assertSame('1', $evidence->vat_condition_code);
        $this->assertSame('DNI 12.345.678', $document->fresh()->recipient_snapshot['document']);
        $this->assertSame(1, FiscalDocumentRecipientEvidence::query()->count());
    }

    public function test_conflicting_second_recipient_evidence_fails_closed(): void
    {
        [$admin, $document] = $this->documentFixture();
        $manager = app(FiscalDocumentRecipientEvidenceManager::class);

        $manager->record(new FiscalDocumentRecipientEvidenceData(
            $document->id,
            '80',
            '20111111119',
            '1'
        ), $admin);

        $this->expectException(DomainException::class);

        $manager->record(new FiscalDocumentRecipientEvidenceData(
            $document->id,
            '96',
            '20111111119',
            '1'
        ), $admin);
    }

    public function test_profile_is_consistency_control_not_silent_inference(): void
    {
        [$admin, $document] = $this->documentFixture();
        $manager = app(FiscalDocumentRecipientEvidenceManager::class);

        $this->assertNull($document->recipientEvidence()->first());

        try {
            $manager->record(new FiscalDocumentRecipientEvidenceData(
                $document->id,
                '80',
                '20999999999',
                '1'
            ), $admin);
            $this->fail('Se esperaba rechazo por inconsistencia con el perfil fiscal explícito.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        $this->assertNull($document->recipientEvidence()->first());
    }

    public function test_evidence_cannot_be_added_after_authorization_attempt(): void
    {
        [$admin, $document] = $this->documentFixture();

        FiscalAuthorizationAttempt::query()->create([
            'organization_id' => $admin->current_organization_id,
            'fiscal_document_id' => $document->id,
            'attempt_number' => 1,
            'requested_at' => now(),
            'recorded_by_user_id' => $admin->id,
            'idempotency_key' => 'recipient-evidence-attempt',
            'fingerprint' => str_repeat('a', 64),
        ]);

        $this->expectException(DomainException::class);

        app(FiscalDocumentRecipientEvidenceManager::class)->record(
            new FiscalDocumentRecipientEvidenceData(
                $document->id,
                '80',
                '20111111119',
                '1'
            ),
            $admin
        );
    }

    public function test_model_and_database_preserve_recipient_evidence_immutability(): void
    {
        [$admin, $document] = $this->documentFixture();
        $evidence = app(FiscalDocumentRecipientEvidenceManager::class)->record(
            new FiscalDocumentRecipientEvidenceData(
                $document->id,
                '80',
                '20111111119',
                '1'
            ),
            $admin
        );

        try {
            $evidence->update(['vat_condition_code' => '5']);
            $this->fail('Se esperaba inmutabilidad de modelo.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        try {
            DB::table('fiscal_document_recipient_evidence')
                ->where('id', $evidence->id)
                ->update(['vat_condition_code' => '5']);
            $this->fail('Se esperaba inmutabilidad de base de datos.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_only_admin_can_record_recipient_fiscal_evidence(): void
    {
        [$admin, $document] = $this->documentFixture();
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

        app(FiscalDocumentRecipientEvidenceManager::class)->record(
            new FiscalDocumentRecipientEvidenceData(
                $document->id,
                '80',
                '20111111119',
                '1'
            ),
            $viewer
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

        $party = BusinessParty::query()->create([
            'organization_id' => $organization->id,
            'party_type' => BusinessParty::TYPE_PERSON,
            'name' => 'Receptor Fiscal',
            'tax_id' => '20-11111111-9',
        ]);

        FiscalBusinessPartyProfile::query()->create([
            'organization_id' => $organization->id,
            'business_party_id' => $party->id,
            'tax_id' => '20-11111111-9',
            'vat_condition_code' => '1',
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
        ]);

        DB::unprepared('DROP TRIGGER IF EXISTS commerce_sales_guard_insert');

        $sale = CommerceSale::query()->create([
            'organization_id' => $organization->id,
            'sale_number' => 9001,
            'status' => 'building',
            'customer_business_party_id' => $party->id,
            'customer_name_snapshot' => 'Receptor Fiscal',
            'customer_document_snapshot' => 'DNI 12.345.678',
            'currency_code' => 'ARS',
            'service_subtotal_minor' => 0,
            'product_subtotal_minor' => 1000,
            'total_minor' => 1000,
            'recorded_by_user_id' => $admin->id,
            'sold_at' => now(),
            'idempotency_key' => 'recipient-evidence-sale',
            'fingerprint' => str_repeat('b', 64),
        ]);

        $document = FiscalDocument::query()->create([
            'organization_id' => $organization->id,
            'fiscal_organization_profile_id' => $point->fiscal_organization_profile_id,
            'fiscal_point_of_sale_id' => $point->id,
            'commerce_sale_id' => $sale->id,
            'document_type' => FiscalDocumentType::Invoice,
            'issuer_snapshot' => ['legal_name' => 'Empresa Fiscal'],
            'recipient_snapshot' => [
                'name' => 'Receptor Fiscal',
                'document' => 'DNI 12.345.678',
            ],
            'currency_code' => 'ARS',
            'service_subtotal_minor' => 0,
            'product_subtotal_minor' => 1000,
            'total_minor' => 1000,
            'documented_at' => now(),
            'created_by_user_id' => $admin->id,
            'idempotency_key' => 'recipient-evidence-document',
            'fingerprint' => str_repeat('c', 64),
        ]);

        return [$admin, $document];
    }
}
