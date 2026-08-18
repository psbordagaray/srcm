<?php

namespace Tests\Feature\Fiscal;

use App\Domain\Fiscal\FiscalDocumentConceptData;
use App\Domain\Fiscal\FiscalDocumentConceptManager;
use App\Domain\Fiscal\FiscalDocumentIssueDateData;
use App\Domain\Fiscal\FiscalDocumentIssueDateManager;
use App\Domain\Fiscal\FiscalDocumentPaymentDueDateData;
use App\Domain\Fiscal\FiscalDocumentPaymentDueDateManager;
use App\Domain\Fiscal\FiscalOrganizationProfileData;
use App\Domain\Fiscal\FiscalOrganizationProfileManager;
use App\Domain\Fiscal\FiscalPointOfSaleData;
use App\Domain\Fiscal\FiscalPointOfSaleManager;
use App\Enums\FiscalDocumentConcept as FiscalDocumentConceptValue;
use App\Enums\FiscalDocumentType;
use App\Enums\FiscalEnvironment;
use App\Enums\FiscalIntegrationMode;
use App\Enums\UserRole;
use App\Models\CommerceSale;
use App\Models\FiscalAuthorizationAttempt;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentPaymentDueDate;
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

class FiscalDocumentPaymentDueDateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_schema_and_document_relation_are_explicit(): void
    {
        $this->assertTrue(Schema::hasColumns('fiscal_document_payment_due_dates', [
            'organization_id',
            'fiscal_document_id',
            'payment_due_date',
            'recorded_at',
            'recorded_by_user_id',
        ]));

        [$admin, $document] = $this->documentFixture();

        $this->assertNull($document->paymentDueDateRecord()->first());
    }

    public function test_service_due_date_is_explicit_idempotent_and_not_derived_from_other_dates(): void
    {
        [$admin, $document] = $this->documentFixture();

        $this->recordConceptAndIssueDate(
            $admin,
            $document,
            FiscalDocumentConceptValue::Services
        );

        $manager = app(FiscalDocumentPaymentDueDateManager::class);
        $data = new FiscalDocumentPaymentDueDateData(
            $document->id,
            CarbonImmutable::parse('2026-08-20')
        );

        $evidence = $manager->record($data, $admin);
        $again = $manager->record($data, $admin);

        $this->assertSame($evidence->id, $again->id);
        $this->assertSame('2026-08-20', $evidence->payment_due_date->toDateString());
        $this->assertSame('2026-08-12', $document->issueDateRecord->issue_date->toDateString());
        $this->assertSame('2026-08-10', $document->conceptRecord->service_period_to->toDateString());
        $this->assertSame(1, FiscalDocumentPaymentDueDate::query()->count());
    }

    public function test_products_and_services_accept_explicit_due_date(): void
    {
        [$admin, $document] = $this->documentFixture();

        $this->recordConceptAndIssueDate(
            $admin,
            $document,
            FiscalDocumentConceptValue::ProductsAndServices
        );

        $evidence = app(FiscalDocumentPaymentDueDateManager::class)->record(
            new FiscalDocumentPaymentDueDateData(
                $document->id,
                CarbonImmutable::parse('2026-08-12')
            ),
            $admin
        );

        $this->assertSame('2026-08-12', $evidence->payment_due_date->toDateString());
    }

    public function test_products_only_do_not_accept_payment_due_date_in_this_subcut(): void
    {
        [$admin, $document] = $this->documentFixture();

        app(FiscalDocumentConceptManager::class)->record(
            new FiscalDocumentConceptData(
                $document->id,
                FiscalDocumentConceptValue::Products,
                null,
                null
            ),
            $admin
        );

        app(FiscalDocumentIssueDateManager::class)->record(
            new FiscalDocumentIssueDateData(
                $document->id,
                CarbonImmutable::parse('2026-08-12')
            ),
            $admin
        );

        $this->expectException(DomainException::class);

        app(FiscalDocumentPaymentDueDateManager::class)->record(
            new FiscalDocumentPaymentDueDateData(
                $document->id,
                CarbonImmutable::parse('2026-08-20')
            ),
            $admin
        );
    }

    public function test_due_date_requires_explicit_concept_first(): void
    {
        [$admin, $document] = $this->documentFixture();

        app(FiscalDocumentIssueDateManager::class)->record(
            new FiscalDocumentIssueDateData(
                $document->id,
                CarbonImmutable::parse('2026-08-12')
            ),
            $admin
        );

        $this->expectException(DomainException::class);

        app(FiscalDocumentPaymentDueDateManager::class)->record(
            new FiscalDocumentPaymentDueDateData(
                $document->id,
                CarbonImmutable::parse('2026-08-20')
            ),
            $admin
        );
    }

    public function test_due_date_requires_explicit_issue_date_first(): void
    {
        [$admin, $document] = $this->documentFixture();

        app(FiscalDocumentConceptManager::class)->record(
            new FiscalDocumentConceptData(
                $document->id,
                FiscalDocumentConceptValue::Services,
                CarbonImmutable::parse('2026-08-01'),
                CarbonImmutable::parse('2026-08-10')
            ),
            $admin
        );

        $this->expectException(DomainException::class);

        app(FiscalDocumentPaymentDueDateManager::class)->record(
            new FiscalDocumentPaymentDueDateData(
                $document->id,
                CarbonImmutable::parse('2026-08-20')
            ),
            $admin
        );
    }

    public function test_due_date_cannot_precede_issue_date(): void
    {
        [$admin, $document] = $this->documentFixture();

        $this->recordConceptAndIssueDate(
            $admin,
            $document,
            FiscalDocumentConceptValue::Services
        );

        $this->expectException(DomainException::class);

        app(FiscalDocumentPaymentDueDateManager::class)->record(
            new FiscalDocumentPaymentDueDateData(
                $document->id,
                CarbonImmutable::parse('2026-08-11')
            ),
            $admin
        );
    }

    public function test_conflicting_second_due_date_fails_closed(): void
    {
        [$admin, $document] = $this->documentFixture();

        $this->recordConceptAndIssueDate(
            $admin,
            $document,
            FiscalDocumentConceptValue::Services
        );

        $manager = app(FiscalDocumentPaymentDueDateManager::class);

        $manager->record(
            new FiscalDocumentPaymentDueDateData(
                $document->id,
                CarbonImmutable::parse('2026-08-20')
            ),
            $admin
        );

        $this->expectException(DomainException::class);

        $manager->record(
            new FiscalDocumentPaymentDueDateData(
                $document->id,
                CarbonImmutable::parse('2026-08-21')
            ),
            $admin
        );
    }

    public function test_due_date_cannot_be_added_after_authorization_attempt(): void
    {
        [$admin, $document] = $this->documentFixture();

        $this->recordConceptAndIssueDate(
            $admin,
            $document,
            FiscalDocumentConceptValue::Services
        );

        FiscalAuthorizationAttempt::query()->create([
            'organization_id' => $admin->current_organization_id,
            'fiscal_document_id' => $document->id,
            'attempt_number' => 1,
            'requested_at' => now(),
            'recorded_by_user_id' => $admin->id,
            'idempotency_key' => 'payment-due-date-attempt',
            'fingerprint' => str_repeat('d', 64),
        ]);

        $this->expectException(DomainException::class);

        app(FiscalDocumentPaymentDueDateManager::class)->record(
            new FiscalDocumentPaymentDueDateData(
                $document->id,
                CarbonImmutable::parse('2026-08-20')
            ),
            $admin
        );
    }

    public function test_model_and_database_preserve_due_date_immutability(): void
    {
        [$admin, $document] = $this->documentFixture();

        $this->recordConceptAndIssueDate(
            $admin,
            $document,
            FiscalDocumentConceptValue::Services
        );

        $evidence = app(FiscalDocumentPaymentDueDateManager::class)->record(
            new FiscalDocumentPaymentDueDateData(
                $document->id,
                CarbonImmutable::parse('2026-08-20')
            ),
            $admin
        );

        try {
            $evidence->update(['payment_due_date' => '2026-08-21']);
            $this->fail('Se esperaba inmutabilidad de modelo.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        try {
            DB::table('fiscal_document_payment_due_dates')
                ->where('id', $evidence->id)
                ->update(['payment_due_date' => '2026-08-21']);

            $this->fail('Se esperaba inmutabilidad de base de datos.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_database_rejects_product_due_date_even_if_manager_is_bypassed(): void
    {
        [$admin, $document] = $this->documentFixture();

        app(FiscalDocumentConceptManager::class)->record(
            new FiscalDocumentConceptData(
                $document->id,
                FiscalDocumentConceptValue::Products,
                null,
                null
            ),
            $admin
        );

        app(FiscalDocumentIssueDateManager::class)->record(
            new FiscalDocumentIssueDateData(
                $document->id,
                CarbonImmutable::parse('2026-08-12')
            ),
            $admin
        );

        $this->expectException(QueryException::class);

        DB::table('fiscal_document_payment_due_dates')->insert([
            'organization_id' => $admin->current_organization_id,
            'fiscal_document_id' => $document->id,
            'payment_due_date' => '2026-08-20',
            'recorded_at' => now(),
            'recorded_by_user_id' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_only_admin_can_record_payment_due_date(): void
    {
        [$admin, $document] = $this->documentFixture();

        $this->recordConceptAndIssueDate(
            $admin,
            $document,
            FiscalDocumentConceptValue::Services
        );

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

        app(FiscalDocumentPaymentDueDateManager::class)->record(
            new FiscalDocumentPaymentDueDateData(
                $document->id,
                CarbonImmutable::parse('2026-08-20')
            ),
            $viewer
        );
    }

    private function recordConceptAndIssueDate(
        User $admin,
        FiscalDocument $document,
        FiscalDocumentConceptValue $concept
    ): void {
        app(FiscalDocumentConceptManager::class)->record(
            new FiscalDocumentConceptData(
                $document->id,
                $concept,
                CarbonImmutable::parse('2026-08-01'),
                CarbonImmutable::parse('2026-08-10')
            ),
            $admin
        );

        app(FiscalDocumentIssueDateManager::class)->record(
            new FiscalDocumentIssueDateData(
                $document->id,
                CarbonImmutable::parse('2026-08-12')
            ),
            $admin
        );

        $document->refresh();
        $document->load(['conceptRecord', 'issueDateRecord']);
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
            'sale_number' => 9301,
            'status' => 'building',
            'customer_name_snapshot' => 'Receptor Vencimiento Fiscal',
            'customer_document_snapshot' => 'DNI 12.345.678',
            'currency_code' => 'ARS',
            'service_subtotal_minor' => 1000,
            'product_subtotal_minor' => 0,
            'total_minor' => 1000,
            'recorded_by_user_id' => $admin->id,
            'sold_at' => CarbonImmutable::parse('2026-08-09 10:00:00'),
            'idempotency_key' => 'payment-due-date-sale',
            'fingerprint' => str_repeat('a', 64),
        ]);

        $document = FiscalDocument::query()->create([
            'organization_id' => $organization->id,
            'fiscal_organization_profile_id' => $point->fiscal_organization_profile_id,
            'fiscal_point_of_sale_id' => $point->id,
            'commerce_sale_id' => $sale->id,
            'document_type' => FiscalDocumentType::Invoice,
            'issuer_snapshot' => ['legal_name' => 'Empresa Fiscal'],
            'recipient_snapshot' => [
                'name' => 'Receptor Vencimiento Fiscal',
                'document' => 'DNI 12.345.678',
            ],
            'currency_code' => 'ARS',
            'service_subtotal_minor' => 1000,
            'product_subtotal_minor' => 0,
            'total_minor' => 1000,
            'documented_at' => CarbonImmutable::parse('2026-08-11 11:00:00'),
            'created_by_user_id' => $admin->id,
            'idempotency_key' => 'payment-due-date-document',
            'fingerprint' => str_repeat('b', 64),
        ]);

        return [$admin, $document];
    }
}
