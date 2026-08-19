<?php

namespace Tests\Feature\Fiscal;

use App\Domain\Fiscal\FiscalAdjustmentData;
use App\Domain\Fiscal\FiscalAdjustmentLineData;
use App\Domain\Fiscal\FiscalAdjustmentManager;
use App\Domain\Fiscal\FiscalAssociatedVoucherData;
use App\Domain\Fiscal\FiscalAuthorizationFactData;
use App\Domain\Fiscal\FiscalAuthorizationFactManager;
use App\Domain\Fiscal\FiscalDocumentAssociationData;
use App\Domain\Fiscal\FiscalDocumentAssociationManager;
use App\Domain\Fiscal\FiscalDocumentIssueDateData;
use App\Domain\Fiscal\FiscalDocumentIssueDateManager;
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
use App\Models\FiscalAuthorizationAttempt;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentAssociationEvidence;
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

class FiscalDocumentAssociationEvidenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_schema_relation_and_sqlite_authorization_gate_are_explicit(): void
    {
        $this->assertTrue(Schema::hasTable('fiscal_document_association_evidence'));
        $this->assertTrue(Schema::hasColumns('fiscal_document_association_evidence', [
            'organization_id',
            'fiscal_document_id',
            'mode',
            'associated_vouchers',
            'associated_voucher_count',
            'period_from_date',
            'period_to_date',
            'fingerprint',
            'recorded_at',
            'recorded_by_user_id',
        ]));

        $this->assertInstanceOf(
            \Illuminate\Database\Eloquent\Relations\HasOne::class,
            (new FiscalDocument())->associationEvidence()
        );

        if (DB::getDriverName() === 'sqlite') {
            $triggerNames = collect(
                DB::select("SELECT name FROM sqlite_master WHERE type = 'trigger' ORDER BY name")
            )->pluck('name')->all();

            $this->assertContains(
                'fiscal_adjustment_authorization_association_gate_insert',
                $triggerNames
            );
            $this->assertNotContains(
                'fiscal_adjustment_authorization_block_insert',
                $triggerNames
            );
            $this->assertContains(
                'fiscal_document_association_immutable_update',
                $triggerNames
            );
        }
    }

    public function test_vouchers_mode_is_canonical_order_independent_and_idempotent(): void
    {
        [$admin, $point] = $this->fiscalConfiguration();
        $document = $this->adjustment($admin, $point->id, 'assoc:vouchers');
        $manager = app(FiscalDocumentAssociationManager::class);

        $first = $manager->record(
            new FiscalDocumentAssociationData(
                $document->id,
                FiscalDocumentAssociationManager::MODE_VOUCHERS,
                [
                    new FiscalAssociatedVoucherData(6, 2, 90, '20123456786', CarbonImmutable::parse('2026-08-01')),
                    new FiscalAssociatedVoucherData(1, 1, 12),
                ]
            ),
            $admin
        );

        $again = $manager->record(
            new FiscalDocumentAssociationData(
                $document->id,
                FiscalDocumentAssociationManager::MODE_VOUCHERS,
                [
                    new FiscalAssociatedVoucherData(1, 1, 12),
                    new FiscalAssociatedVoucherData(6, 2, 90, '20123456786', CarbonImmutable::parse('2026-08-01')),
                ]
            ),
            $admin
        );

        $this->assertSame($first->id, $again->id);
        $this->assertSame(FiscalDocumentAssociationManager::MODE_VOUCHERS, $first->mode);
        $this->assertSame(2, $first->associated_voucher_count);
        $this->assertNull($first->period_from_date);
        $this->assertNull($first->period_to_date);
        $this->assertSame(1, $first->associated_vouchers[0]['voucher_type_code']);
        $this->assertSame(6, $first->associated_vouchers[1]['voucher_type_code']);
        $this->assertSame(1, FiscalDocumentAssociationEvidence::query()->count());
    }

    public function test_period_mode_requires_explicit_issue_date_and_valid_range(): void
    {
        [$admin, $point] = $this->fiscalConfiguration();
        $document = $this->adjustment($admin, $point->id, 'assoc:period');
        $manager = app(FiscalDocumentAssociationManager::class);

        try {
            $manager->record(
                new FiscalDocumentAssociationData(
                    $document->id,
                    FiscalDocumentAssociationManager::MODE_PERIOD,
                    [],
                    CarbonImmutable::parse('2026-07-01'),
                    CarbonImmutable::parse('2026-07-31')
                ),
                $admin
            );
            $this->fail('PeriodoAsoc sin CbteFch explícito debió fallar.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        app(FiscalDocumentIssueDateManager::class)->record(
            new FiscalDocumentIssueDateData(
                $document->id,
                CarbonImmutable::parse('2026-08-18')
            ),
            $admin
        );

        try {
            $manager->record(
                new FiscalDocumentAssociationData(
                    $document->id,
                    FiscalDocumentAssociationManager::MODE_PERIOD,
                    [],
                    CarbonImmutable::parse('2026-08-20'),
                    CarbonImmutable::parse('2026-08-19')
                ),
                $admin
            );
            $this->fail('PeriodoAsoc invertido debió fallar.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        try {
            $manager->record(
                new FiscalDocumentAssociationData(
                    $document->id,
                    FiscalDocumentAssociationManager::MODE_PERIOD,
                    [],
                    CarbonImmutable::parse('2026-08-01'),
                    CarbonImmutable::parse('2026-08-19')
                ),
                $admin
            );
            $this->fail('PeriodoAsoc posterior a CbteFch debió fallar.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        $evidence = $manager->record(
            new FiscalDocumentAssociationData(
                $document->id,
                FiscalDocumentAssociationManager::MODE_PERIOD,
                [],
                CarbonImmutable::parse('2026-08-01'),
                CarbonImmutable::parse('2026-08-18')
            ),
            $admin
        );

        $this->assertSame(FiscalDocumentAssociationManager::MODE_PERIOD, $evidence->mode);
        $this->assertSame('2026-08-01', $evidence->period_from_date->toDateString());
        $this->assertSame('2026-08-18', $evidence->period_to_date->toDateString());
        $this->assertSame(0, $evidence->associated_voucher_count);
        $this->assertNull($evidence->associated_vouchers);
    }

    public function test_modes_cannot_be_mixed(): void
    {
        [$admin, $point] = $this->fiscalConfiguration();
        $document = $this->adjustment($admin, $point->id, 'assoc:mixed');

        $this->expectException(DomainException::class);

        app(FiscalDocumentAssociationManager::class)->record(
            new FiscalDocumentAssociationData(
                $document->id,
                FiscalDocumentAssociationManager::MODE_VOUCHERS,
                [new FiscalAssociatedVoucherData(1, 1, 1)],
                CarbonImmutable::parse('2026-08-01'),
                CarbonImmutable::parse('2026-08-02')
            ),
            $admin
        );
    }

    public function test_voucher_identity_ranges_cuit_and_duplicates_fail_closed(): void
    {
        [$admin, $point] = $this->fiscalConfiguration();
        $manager = app(FiscalDocumentAssociationManager::class);

        $cases = [
            [new FiscalAssociatedVoucherData(0, 1, 1)],
            [new FiscalAssociatedVoucherData(1, 99999, 1)],
            [new FiscalAssociatedVoucherData(1, 1, 99999999)],
            [new FiscalAssociatedVoucherData(1, 1, 1, '20-12345678-6')],
            [
                new FiscalAssociatedVoucherData(1, 1, 1),
                new FiscalAssociatedVoucherData(1, 1, 1),
            ],
        ];

        foreach ($cases as $index => $vouchers) {
            $document = $this->adjustment(
                $admin,
                $point->id,
                'assoc:invalid:' . $index
            );

            try {
                $manager->record(
                    new FiscalDocumentAssociationData(
                        $document->id,
                        FiscalDocumentAssociationManager::MODE_VOUCHERS,
                        $vouchers
                    ),
                    $admin
                );
                $this->fail('CbtesAsoc inválido debió fallar. Caso ' . $index);
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, FiscalDocumentAssociationEvidence::query()->count());
    }

    public function test_conflicting_second_association_fails_closed(): void
    {
        [$admin, $point] = $this->fiscalConfiguration();
        $document = $this->adjustment($admin, $point->id, 'assoc:conflict');
        $manager = app(FiscalDocumentAssociationManager::class);

        $manager->record(
            new FiscalDocumentAssociationData(
                $document->id,
                FiscalDocumentAssociationManager::MODE_VOUCHERS,
                [new FiscalAssociatedVoucherData(1, 1, 10)]
            ),
            $admin
        );

        $this->expectException(DomainException::class);

        $manager->record(
            new FiscalDocumentAssociationData(
                $document->id,
                FiscalDocumentAssociationManager::MODE_VOUCHERS,
                [new FiscalAssociatedVoucherData(1, 1, 11)]
            ),
            $admin
        );
    }

    public function test_authorization_without_association_is_blocked_by_domain_and_database(): void
    {
        [$admin, $point] = $this->fiscalConfiguration();
        $document = $this->adjustment($admin, $point->id, 'assoc:auth-missing');

        try {
            app(FiscalAuthorizationFactManager::class)->record(
                new FiscalAuthorizationFactData(
                    $document->id,
                    FiscalAuthorizationOutcome::Unknown,
                    null,
                    'assoc:auth-missing:fact'
                ),
                $admin
            );
            $this->fail('La autorización sin asociación debió fallar.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, FiscalAuthorizationAttempt::query()->count());

        $this->expectException(QueryException::class);

        DB::table('fiscal_authorization_attempts')->insert([
            'organization_id' => $admin->current_organization_id,
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'fiscal_document_id' => $document->id,
            'attempt_number' => 1,
            'requested_at' => now(),
            'recorded_by_user_id' => $admin->id,
            'idempotency_key' => 'assoc:db-bypass',
            'fingerprint' => str_repeat('a', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_valid_voucher_association_allows_authorization_fact_recording(): void
    {
        [$admin, $point] = $this->fiscalConfiguration();
        $document = $this->adjustment($admin, $point->id, 'assoc:auth-valid');

        app(FiscalDocumentAssociationManager::class)->record(
            new FiscalDocumentAssociationData(
                $document->id,
                FiscalDocumentAssociationManager::MODE_VOUCHERS,
                [new FiscalAssociatedVoucherData(1, 1, 55, '20123456786')]
            ),
            $admin
        );

        $attempt = app(FiscalAuthorizationFactManager::class)->record(
            new FiscalAuthorizationFactData(
                $document->id,
                FiscalAuthorizationOutcome::Unknown,
                'ASSOCIATION_GATE_TEST',
                'assoc:auth-valid:fact'
            ),
            $admin
        );

        $this->assertSame($document->id, $attempt->fiscal_document_id);
        $this->assertSame(1, $attempt->attempt_number);
        $this->assertSame(FiscalAuthorizationOutcome::Unknown, $attempt->response->outcome);
    }

    public function test_new_association_cannot_be_added_after_authorization_attempt(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('El fixture de bypass controlado usa el trigger SQLite.');
        }

        [$admin, $point] = $this->fiscalConfiguration();
        $document = $this->adjustment($admin, $point->id, 'assoc:late');

        DB::unprepared('DROP TRIGGER IF EXISTS fiscal_adjustment_authorization_association_gate_insert');

        DB::table('fiscal_authorization_attempts')->insert([
            'organization_id' => $admin->current_organization_id,
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'fiscal_document_id' => $document->id,
            'attempt_number' => 1,
            'requested_at' => now(),
            'recorded_by_user_id' => $admin->id,
            'idempotency_key' => 'assoc:late:attempt',
            'fingerprint' => str_repeat('b', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(DomainException::class);

        app(FiscalDocumentAssociationManager::class)->record(
            new FiscalDocumentAssociationData(
                $document->id,
                FiscalDocumentAssociationManager::MODE_VOUCHERS,
                [new FiscalAssociatedVoucherData(1, 1, 9)]
            ),
            $admin
        );
    }

    public function test_association_evidence_is_immutable_in_model_and_database(): void
    {
        [$admin, $point] = $this->fiscalConfiguration();
        $document = $this->adjustment($admin, $point->id, 'assoc:immutable');
        $evidence = app(FiscalDocumentAssociationManager::class)->record(
            new FiscalDocumentAssociationData(
                $document->id,
                FiscalDocumentAssociationManager::MODE_VOUCHERS,
                [new FiscalAssociatedVoucherData(1, 1, 19)]
            ),
            $admin
        );

        try {
            $evidence->update(['mode' => FiscalDocumentAssociationManager::MODE_PERIOD]);
            $this->fail('La evidencia debió ser inmutable en Eloquent.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        if (DB::getDriverName() === 'sqlite') {
            try {
                DB::table('fiscal_document_association_evidence')
                    ->where('id', $evidence->id)
                    ->update(['associated_voucher_count' => 99]);
                $this->fail('La evidencia debió ser inmutable en SQLite.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(1, $evidence->fresh()->associated_voucher_count);
    }

    public function test_only_admin_can_record_association_evidence(): void
    {
        [$admin, $point] = $this->fiscalConfiguration();
        $document = $this->adjustment($admin, $point->id, 'assoc:viewer');

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

        app(FiscalDocumentAssociationManager::class)->record(
            new FiscalDocumentAssociationData(
                $document->id,
                FiscalDocumentAssociationManager::MODE_VOUCHERS,
                [new FiscalAssociatedVoucherData(1, 1, 25)]
            ),
            $viewer
        );
    }

    public function test_fce_voucher_codes_remain_fail_closed_for_authorization(): void
    {
        [$admin, $point] = $this->fiscalConfiguration();
        $document = $this->adjustment($admin, $point->id, 'assoc:fce');

        app(FiscalDocumentAssociationManager::class)->record(
            new FiscalDocumentAssociationData(
                $document->id,
                FiscalDocumentAssociationManager::MODE_VOUCHERS,
                [new FiscalAssociatedVoucherData(1, 1, 30)]
            ),
            $admin
        );

        DB::table('fiscal_document_classifications')->insert([
            'organization_id' => $admin->current_organization_id,
            'fiscal_document_id' => $document->id,
            'voucher_class' => 'A',
            'voucher_code' => 202,
            'classified_at' => now(),
            'classified_by_user_id' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(DomainException::class);

        app(FiscalAuthorizationFactManager::class)->record(
            new FiscalAuthorizationFactData(
                $document->id,
                FiscalAuthorizationOutcome::Unknown,
                null,
                'assoc:fce:fact'
            ),
            $admin
        );
    }

    private function adjustment(User $admin, int $pointId, string $key): FiscalDocument
    {
        return app(FiscalAdjustmentManager::class)->record(
            new FiscalAdjustmentData(
                $pointId,
                FiscalDocumentType::CreditNote,
                [
                    'name' => 'Receptor Asociación',
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
                $key
            ),
            $admin
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
