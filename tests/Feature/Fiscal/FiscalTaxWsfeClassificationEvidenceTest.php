<?php

namespace Tests\Feature\Fiscal;

use App\Domain\Fiscal\FiscalAdjustmentData;
use App\Domain\Fiscal\FiscalAdjustmentLineData;
use App\Domain\Fiscal\FiscalAdjustmentManager;
use App\Domain\Fiscal\FiscalAssociatedVoucherData;
use App\Domain\Fiscal\FiscalDocumentAssociationData;
use App\Domain\Fiscal\FiscalDocumentAssociationManager;
use App\Domain\Fiscal\FiscalOrganizationProfileData;
use App\Domain\Fiscal\FiscalOrganizationProfileManager;
use App\Domain\Fiscal\FiscalPointOfSaleData;
use App\Domain\Fiscal\FiscalPointOfSaleManager;
use App\Domain\Fiscal\FiscalTaxCompositionData;
use App\Domain\Fiscal\FiscalTaxCompositionManager;
use App\Domain\Fiscal\FiscalTaxWsfeClassificationData;
use App\Domain\Fiscal\FiscalTaxWsfeClassificationManager;
use App\Domain\Fiscal\FiscalTaxWsfeIdentityData;
use App\Enums\CommerceSaleLineType;
use App\Enums\FiscalDocumentType;
use App\Enums\FiscalEnvironment;
use App\Enums\FiscalIntegrationMode;
use App\Enums\FiscalTaxWsfeBucket;
use App\Enums\UserRole;
use App\Models\FiscalAuthorizationAttempt;
use App\Models\FiscalDocumentTaxWsfeIdentity;
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

class FiscalTaxWsfeClassificationEvidenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_schema_and_relation_are_explicit_and_additive(): void
    {
        [$admin, $document] = $this->taxDocument(
            'schema'
        );

        $this->assertTrue(
            Schema::hasColumns(
                'fiscal_document_tax_wsfe_identities',
                [
                    'organization_id',
                    'fiscal_document_id',
                    'fiscal_document_tax_id',
                    'bucket',
                    'arca_id',
                    'tribute_description',
                    'recorded_at',
                    'recorded_by_user_id',
                ]
            )
        );

        $component = $document->taxComponents->first();

        $this->assertFalse($component->wsfeIdentity()->exists());
        $this->assertNotNull($admin);
    }

    public function test_complete_explicit_set_records_iva_and_tributo_without_tax_code_inference(): void
    {
        [$admin, $document] = $this->taxDocument(
            'record'
        );

        $components = $document->taxComponents;

        $data = new FiscalTaxWsfeClassificationData(
            $document->id,
            [
                new FiscalTaxWsfeIdentityData(
                    $components[0]->id,
                    FiscalTaxWsfeBucket::Iva,
                    5
                ),
                new FiscalTaxWsfeIdentityData(
                    $components[1]->id,
                    FiscalTaxWsfeBucket::Tributo,
                    99,
                    'Impuesto Municipal'
                ),
            ]
        );

        $manager = app(
            FiscalTaxWsfeClassificationManager::class
        );

        $ready = $manager->record($data, $admin);

        $this->assertCount(
            2,
            $ready->taxComponents
        );

        $this->assertSame(
            FiscalTaxWsfeBucket::Iva,
            $ready->taxComponents[0]->wsfeIdentity->bucket
        );

        $this->assertSame(
            5,
            $ready->taxComponents[0]->wsfeIdentity->arca_id
        );

        $this->assertNull(
            $ready->taxComponents[0]->wsfeIdentity->tribute_description
        );

        $this->assertSame(
            FiscalTaxWsfeBucket::Tributo,
            $ready->taxComponents[1]->wsfeIdentity->bucket
        );

        $this->assertSame(
            99,
            $ready->taxComponents[1]->wsfeIdentity->arca_id
        );

        $this->assertSame(
            'Impuesto Municipal',
            $ready->taxComponents[1]->wsfeIdentity->tribute_description
        );

        $this->assertSame(
            'internal-iva-name-do-not-infer',
            $ready->taxComponents[0]->tax_code
        );

        $this->assertSame(
            'internal-tribute-name-do-not-infer',
            $ready->taxComponents[1]->tax_code
        );
    }

    public function test_exact_complete_set_is_idempotent_but_conflict_fails_closed(): void
    {
        [$admin, $document] = $this->taxDocument(
            'idempotency'
        );

        $components = $document->taxComponents;

        $data = $this->identities(
            $document->id,
            $components[0]->id,
            $components[1]->id
        );

        $manager = app(
            FiscalTaxWsfeClassificationManager::class
        );

        $first = $manager->record(
            $data,
            $admin
        );

        $again = $manager->record(
            $data,
            $admin
        );

        $this->assertSame(
            $first->id,
            $again->id
        );

        $this->assertSame(
            2,
            FiscalDocumentTaxWsfeIdentity::query()
                ->where(
                    'fiscal_document_id',
                    $document->id
                )
                ->count()
        );

        $this->expectException(
            DomainException::class
        );

        $manager->record(
            new FiscalTaxWsfeClassificationData(
                $document->id,
                [
                    new FiscalTaxWsfeIdentityData(
                        $components[0]->id,
                        FiscalTaxWsfeBucket::Iva,
                        4
                    ),
                    new FiscalTaxWsfeIdentityData(
                        $components[1]->id,
                        FiscalTaxWsfeBucket::Tributo,
                        99,
                        'Impuesto Municipal'
                    ),
                ]
            ),
            $admin
        );
    }

    public function test_full_set_rejects_missing_duplicate_and_foreign_components(): void
    {
        [$admin, $document] = $this->taxDocument(
            'coverage'
        );

        $components = $document->taxComponents;
        $manager = app(
            FiscalTaxWsfeClassificationManager::class
        );

        foreach ([
            [
                new FiscalTaxWsfeIdentityData(
                    $components[0]->id,
                    FiscalTaxWsfeBucket::Iva,
                    5
                ),
            ],
            [
                new FiscalTaxWsfeIdentityData(
                    $components[0]->id,
                    FiscalTaxWsfeBucket::Iva,
                    5
                ),
                new FiscalTaxWsfeIdentityData(
                    $components[0]->id,
                    FiscalTaxWsfeBucket::Tributo,
                    99
                ),
            ],
        ] as $invalid) {
            try {
                $manager->record(
                    new FiscalTaxWsfeClassificationData(
                        $document->id,
                        $invalid
                    ),
                    $admin
                );

                $this->fail(
                    'El set incompleto o duplicado debió fallar.'
                );
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }

        [, $otherDocument] = $this->taxDocument(
            'coverage-foreign',
            921
        );

        $this->expectException(
            DomainException::class
        );

        $manager->record(
            new FiscalTaxWsfeClassificationData(
                $document->id,
                [
                    new FiscalTaxWsfeIdentityData(
                        $components[0]->id,
                        FiscalTaxWsfeBucket::Iva,
                        5
                    ),
                    new FiscalTaxWsfeIdentityData(
                        $otherDocument->taxComponents[1]->id,
                        FiscalTaxWsfeBucket::Tributo,
                        99
                    ),
                ]
            ),
            $admin
        );
    }

    public function test_provider_identity_shape_is_explicit_and_fail_closed(): void
    {
        [$admin, $document] = $this->taxDocument(
            'shape'
        );

        $components = $document->taxComponents;
        $manager = app(
            FiscalTaxWsfeClassificationManager::class
        );

        $invalidSets = [
            [
                new FiscalTaxWsfeIdentityData(
                    $components[0]->id,
                    FiscalTaxWsfeBucket::Iva,
                    0
                ),
                new FiscalTaxWsfeIdentityData(
                    $components[1]->id,
                    FiscalTaxWsfeBucket::Tributo,
                    99
                ),
            ],
            [
                new FiscalTaxWsfeIdentityData(
                    $components[0]->id,
                    FiscalTaxWsfeBucket::Iva,
                    5,
                    'No corresponde'
                ),
                new FiscalTaxWsfeIdentityData(
                    $components[1]->id,
                    FiscalTaxWsfeBucket::Tributo,
                    99
                ),
            ],
            [
                new FiscalTaxWsfeIdentityData(
                    $components[0]->id,
                    FiscalTaxWsfeBucket::Iva,
                    5
                ),
                new FiscalTaxWsfeIdentityData(
                    $components[1]->id,
                    FiscalTaxWsfeBucket::Tributo,
                    100
                ),
            ],
            [
                new FiscalTaxWsfeIdentityData(
                    $components[0]->id,
                    FiscalTaxWsfeBucket::Iva,
                    5
                ),
                new FiscalTaxWsfeIdentityData(
                    $components[1]->id,
                    FiscalTaxWsfeBucket::Tributo,
                    99,
                    str_repeat('x', 81)
                ),
            ],
        ];

        foreach ($invalidSets as $invalid) {
            try {
                $manager->record(
                    new FiscalTaxWsfeClassificationData(
                        $document->id,
                        $invalid
                    ),
                    $admin
                );

                $this->fail(
                    'La identidad WSFE inválida debió fallar.'
                );
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(
            0,
            FiscalDocumentTaxWsfeIdentity::query()
                ->where(
                    'fiscal_document_id',
                    $document->id
                )
                ->count()
        );
    }

    public function test_complete_assertion_returns_deterministic_provider_ready_evidence(): void
    {
        [$admin, $document] = $this->taxDocument(
            'ready'
        );

        $components = $document->taxComponents;
        $manager = app(
            FiscalTaxWsfeClassificationManager::class
        );

        $manager->record(
            $this->identities(
                $document->id,
                $components[0]->id,
                $components[1]->id
            ),
            $admin
        );

        $ready = $manager
            ->assertCompleteForRequestComposition(
                $document
            );

        $this->assertSame(
            [1, 2],
            array_column(
                $ready,
                'position'
            )
        );

        $this->assertSame(
            [
                FiscalTaxWsfeBucket::Iva,
                FiscalTaxWsfeBucket::Tributo,
            ],
            array_column(
                $ready,
                'bucket'
            )
        );

        $this->assertSame(
            [5, 99],
            array_column(
                $ready,
                'arca_id'
            )
        );

        $this->assertSame(
            [1680, 200],
            array_column(
                $ready,
                'tax_amount_minor'
            )
        );
    }

    public function test_partial_raw_bypass_never_becomes_request_ready(): void
    {
        [$admin, $document] = $this->taxDocument(
            'partial'
        );

        $component = $document->taxComponents[0];

        DB::table(
            'fiscal_document_tax_wsfe_identities'
        )->insert([
            'organization_id' => $document->organization_id,
            'fiscal_document_id' => $document->id,
            'fiscal_document_tax_id' => $component->id,
            'bucket' => 'IVA',
            'arca_id' => 5,
            'tribute_description' => null,
            'recorded_at' => now(),
            'recorded_by_user_id' => $admin->id,
        ]);

        $manager = app(
            FiscalTaxWsfeClassificationManager::class
        );

        try {
            $manager->assertCompleteForRequestComposition(
                $document
            );

            $this->fail(
                'Un set parcial nunca debe quedar listo para FECAE.'
            );
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(
            DomainException::class
        );

        $manager->record(
            $this->identities(
                $document->id,
                $document->taxComponents[0]->id,
                $document->taxComponents[1]->id
            ),
            $admin
        );
    }

    public function test_new_identity_is_blocked_after_authorization_attempt(): void
    {
        [$admin, $document] = $this->taxDocument(
            'post-auth'
        );

        $this->addAssociationAndAttempt(
            $admin,
            $document,
            'post-auth'
        );

        $components = $document->taxComponents;

        $this->expectException(
            DomainException::class
        );

        app(
            FiscalTaxWsfeClassificationManager::class
        )->record(
            $this->identities(
                $document->id,
                $components[0]->id,
                $components[1]->id
            ),
            $admin
        );
    }

    public function test_database_rejects_tenant_shape_post_auth_and_mutation_bypasses(): void
    {
        [$admin, $document] = $this->taxDocument(
            'db-guards'
        );

        $component = $document->taxComponents[0];

        try {
            DB::table(
                'fiscal_document_tax_wsfe_identities'
            )->insert([
                'organization_id' => $document->organization_id + 999,
                'fiscal_document_id' => $document->id,
                'fiscal_document_tax_id' => $component->id,
                'bucket' => 'IVA',
                'arca_id' => 5,
                'tribute_description' => null,
                'recorded_at' => now(),
                'recorded_by_user_id' => $admin->id,
            ]);

            $this->fail(
                'El cruce de tenant debió ser rechazado por DB.'
            );
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        try {
            DB::table(
                'fiscal_document_tax_wsfe_identities'
            )->insert([
                'organization_id' => $document->organization_id,
                'fiscal_document_id' => $document->id,
                'fiscal_document_tax_id' => $component->id,
                'bucket' => 'IVA',
                'arca_id' => 5,
                'tribute_description' => 'No permitido',
                'recorded_at' => now(),
                'recorded_by_user_id' => $admin->id,
            ]);

            $this->fail(
                'La forma IVA inválida debió ser rechazada por DB.'
            );
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        app(
            FiscalTaxWsfeClassificationManager::class
        )->record(
            $this->identities(
                $document->id,
                $document->taxComponents[0]->id,
                $document->taxComponents[1]->id
            ),
            $admin
        );

        $identity = FiscalDocumentTaxWsfeIdentity::query()
            ->where(
                'fiscal_document_id',
                $document->id
            )
            ->orderBy('id')
            ->firstOrFail();

        try {
            DB::table(
                'fiscal_document_tax_wsfe_identities'
            )
                ->where('id', $identity->id)
                ->update(['arca_id' => 4]);

            $this->fail(
                'La identidad debió ser inmutable en DB.'
            );
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        try {
            DB::table(
                'fiscal_document_tax_wsfe_identities'
            )
                ->where('id', $identity->id)
                ->delete();

            $this->fail(
                'La identidad no debe poder borrarse.'
            );
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_database_blocks_raw_identity_insert_after_authorization_attempt(): void
    {
        [$admin, $document] = $this->taxDocument(
            'db-post-auth'
        );

        $this->addAssociationAndAttempt(
            $admin,
            $document,
            'db-post-auth'
        );

        $component = $document->taxComponents[0];

        $this->expectException(
            QueryException::class
        );

        DB::table(
            'fiscal_document_tax_wsfe_identities'
        )->insert([
            'organization_id' => $document->organization_id,
            'fiscal_document_id' => $document->id,
            'fiscal_document_tax_id' => $component->id,
            'bucket' => 'IVA',
            'arca_id' => 5,
            'tribute_description' => null,
            'recorded_at' => now(),
            'recorded_by_user_id' => $admin->id,
        ]);
    }

    public function test_only_admin_can_record_wsfe_tax_identity(): void
    {
        [$admin, $document] = $this->taxDocument(
            'role'
        );

        $viewer = User::factory()->create([
            'role' => UserRole::Viewer,
            'current_organization_id' =>
                $admin->current_organization_id,
        ]);

        OrganizationMembership::withoutEvents(
            fn () => OrganizationMembership::query()
                ->updateOrCreate(
                    [
                        'organization_id' =>
                            $admin->current_organization_id,
                        'user_id' => $viewer->id,
                    ],
                    [
                        'role' => UserRole::Viewer,
                        'active' => true,
                    ]
                )
        );

        $components = $document->taxComponents;

        $this->expectException(
            DomainException::class
        );

        app(
            FiscalTaxWsfeClassificationManager::class
        )->record(
            $this->identities(
                $document->id,
                $components[0]->id,
                $components[1]->id
            ),
            $viewer
        );
    }

    private function identities(
        int $documentId,
        int $ivaTaxId,
        int $tributeTaxId
    ): FiscalTaxWsfeClassificationData {
        return new FiscalTaxWsfeClassificationData(
            $documentId,
            [
                new FiscalTaxWsfeIdentityData(
                    $ivaTaxId,
                    FiscalTaxWsfeBucket::Iva,
                    5
                ),
                new FiscalTaxWsfeIdentityData(
                    $tributeTaxId,
                    FiscalTaxWsfeBucket::Tributo,
                    99,
                    'Impuesto Municipal'
                ),
            ]
        );
    }

    private function addAssociationAndAttempt(
        User $admin,
        $document,
        string $key
    ): void {
        app(
            FiscalDocumentAssociationManager::class
        )->record(
            new FiscalDocumentAssociationData(
                $document->id,
                'VOUCHERS',
                [
                    new FiscalAssociatedVoucherData(
                        1,
                        1,
                        1
                    ),
                ]
            ),
            $admin
        );

        FiscalAuthorizationAttempt::query()->create([
            'organization_id' => $document->organization_id,
            'fiscal_document_id' => $document->id,
            'attempt_number' => 1,
            'requested_at' => now(),
            'recorded_by_user_id' => $admin->id,
            'idempotency_key' => 'tax-wsfe-attempt:'.$key,
            'fingerprint' => str_repeat('a', 64),
        ]);
    }

    private function taxDocument(
        string $key,
        int $pointNumber = 920
    ): array {
        $organization = Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();

        $admin = User::query()
            ->where('email', 'test@example.com')
            ->firstOrFail();

        $admin->forceFill([
            'current_organization_id' =>
                $organization->id,
        ])->saveQuietly();

        app(
            FiscalOrganizationProfileManager::class
        )->save(
            new FiscalOrganizationProfileData(
                'Empresa Fiscal',
                '20-12345678-6',
                '1',
                null,
                '2020-01-01',
                'Av. Fiscal 123',
                'San Rafael',
                'MZA',
                '5600'
            ),
            $admin
        );

        $point = app(
            FiscalPointOfSaleManager::class
        )->create(
            new FiscalPointOfSaleData(
                $pointNumber,
                FiscalEnvironment::Homologation,
                FiscalIntegrationMode::WsfeV1
            ),
            $admin
        );

        $document = app(
            FiscalAdjustmentManager::class
        )->record(
            new FiscalAdjustmentData(
                $point->id,
                FiscalDocumentType::CreditNote,
                ['name' => 'Receptor Fiscal'],
                'ARS',
                0,
                10000,
                10000,
                [
                    new FiscalAdjustmentLineData(
                        1,
                        CommerceSaleLineType::Product,
                        'Producto explícito',
                        '1',
                        10000,
                        10000
                    ),
                ],
                'tax-wsfe-document:'.$key
            ),
            $admin
        );

        app(
            FiscalTaxCompositionManager::class
        )->record(
            new FiscalTaxCompositionData(
                $document->id,
                [
                    [
                        'tax_code' =>
                            'internal-iva-name-do-not-infer',
                        'taxable_base_minor' => 8000,
                        'rate_basis_points' => 2100,
                        'tax_amount_minor' => 1680,
                    ],
                    [
                        'tax_code' =>
                            'internal-tribute-name-do-not-infer',
                        'taxable_base_minor' => 10000,
                        'rate_basis_points' => 200,
                        'tax_amount_minor' => 200,
                    ],
                ],
                'tax-wsfe-composition:'.$key
            ),
            $admin
        );

        return [
            $admin,
            $document
                ->refresh()
                ->load('taxComponents'),
        ];
    }
}
