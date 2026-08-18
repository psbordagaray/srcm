<?php

namespace Tests\Feature\Fiscal;

use App\Domain\Fiscal\FiscalDocumentIssueDateData;
use App\Domain\Fiscal\FiscalDocumentIssueDateManager;
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
use App\Models\FiscalDocumentIssueDate;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FiscalDocumentIssueDateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_schema_and_document_relation_are_explicit(): void
    {
        $this->assertTrue(Schema::hasColumns('fiscal_document_issue_dates', [
            'organization_id',
            'fiscal_document_id',
            'issue_date',
            'recorded_at',
            'recorded_by_user_id',
        ]));

        [$admin, $document] = $this->documentFixture();

        $this->assertNull($document->issueDateRecord()->first());
        $this->assertNotNull($document->documented_at);
        $this->assertNotNull($document->sale->sold_at);
    }

    public function test_issue_date_is_explicit_idempotent_and_independent_from_operational_timestamps(): void
    {
        [$admin, $document] = $this->documentFixture();
        $manager = app(FiscalDocumentIssueDateManager::class);
        $data = new FiscalDocumentIssueDateData(
            $document->id,
            CarbonImmutable::parse('2026-08-12')
        );

        $evidence = $manager->record($data, $admin);
        $again = $manager->record($data, $admin);

        $this->assertSame($evidence->id, $again->id);
        $this->assertSame('2026-08-12', $evidence->issue_date->toDateString());
        $this->assertSame('2026-08-10', $document->sale->sold_at->toDateString());
        $this->assertSame('2026-08-11', $document->documented_at->toDateString());
        $this->assertSame(1, FiscalDocumentIssueDate::query()->count());
    }

    public function test_conflicting_second_issue_date_fails_closed(): void
    {
        [$admin, $document] = $this->documentFixture();
        $manager = app(FiscalDocumentIssueDateManager::class);

        $manager->record(new FiscalDocumentIssueDateData(
            $document->id,
            CarbonImmutable::parse('2026-08-12')
        ), $admin);

        $this->expectException(DomainException::class);

        $manager->record(new FiscalDocumentIssueDateData(
            $document->id,
            CarbonImmutable::parse('2026-08-13')
        ), $admin);
    }

    public function test_issue_date_cannot_be_added_after_authorization_attempt(): void
    {
        [$admin, $document] = $this->documentFixture();

        FiscalAuthorizationAttempt::query()->create([
            'organization_id' => $admin->current_organization_id,
            'fiscal_document_id' => $document->id,
            'attempt_number' => 1,
            'requested_at' => now(),
            'recorded_by_user_id' => $admin->id,
            'idempotency_key' => 'issue-date-attempt',
            'fingerprint' => str_repeat('a', 64),
        ]);

        $this->expectException(DomainException::class);

        app(FiscalDocumentIssueDateManager::class)->record(
            new FiscalDocumentIssueDateData(
                $document->id,
                CarbonImmutable::parse('2026-08-12')
            ),
            $admin
        );
    }

    public function test_model_and_database_preserve_issue_date_immutability(): void
    {
        [$admin, $document] = $this->documentFixture();
        $evidence = app(FiscalDocumentIssueDateManager::class)->record(
            new FiscalDocumentIssueDateData(
                $document->id,
                CarbonImmutable::parse('2026-08-12')
            ),
            $admin
        );

        try {
            $evidence->update(['issue_date' => '2026-08-13']);
            $this->fail('Se esperaba inmutabilidad de modelo.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        try {
            DB::table('fiscal_document_issue_dates')
                ->where('id', $evidence->id)
                ->update(['issue_date' => '2026-08-13']);
            $this->fail('Se esperaba inmutabilidad de base de datos.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_only_admin_can_record_fiscal_issue_date(): void
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

        app(FiscalDocumentIssueDateManager::class)->record(
            new FiscalDocumentIssueDateData(
                $document->id,
                CarbonImmutable::parse('2026-08-12')
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

        DB::unprepared('DROP TRIGGER IF EXISTS commerce_sales_guard_insert');

        $sale = CommerceSale::query()->create([
            'organization_id' => $organization->id,
            'sale_number' => 9101,
            'status' => 'building',
            'customer_name_snapshot' => 'Receptor Fecha Fiscal',
            'customer_document_snapshot' => 'DNI 12.345.678',
            'currency_code' => 'ARS',
            'service_subtotal_minor' => 0,
            'product_subtotal_minor' => 1000,
            'total_minor' => 1000,
            'recorded_by_user_id' => $admin->id,
            'sold_at' => CarbonImmutable::parse('2026-08-10 10:00:00'),
            'idempotency_key' => 'issue-date-sale',
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
                'name' => 'Receptor Fecha Fiscal',
                'document' => 'DNI 12.345.678',
            ],
            'currency_code' => 'ARS',
            'service_subtotal_minor' => 0,
            'product_subtotal_minor' => 1000,
            'total_minor' => 1000,
            'documented_at' => CarbonImmutable::parse('2026-08-11 11:00:00'),
            'created_by_user_id' => $admin->id,
            'idempotency_key' => 'issue-date-document',
            'fingerprint' => str_repeat('c', 64),
        ])->load('sale');

        return [$admin, $document];
    }
}
