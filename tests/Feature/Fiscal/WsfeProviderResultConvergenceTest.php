<?php

namespace Tests\Feature\Fiscal;

use App\Domain\Fiscal\FiscalAuthorizationFactData;
use App\Domain\Fiscal\FiscalAuthorizationFactManager;
use App\Domain\Fiscal\FiscalAuthorizationTransportResult;
use App\Domain\Fiscal\FiscalOrganizationProfileData;
use App\Domain\Fiscal\FiscalOrganizationProfileManager;
use App\Domain\Fiscal\FiscalPointOfSaleData;
use App\Domain\Fiscal\FiscalPointOfSaleManager;
use App\Domain\Fiscal\WsfeFecaeProviderResponseNormalizer;
use App\Domain\Fiscal\WsfeFecaeProviderResultConvergence;
use App\Domain\Fiscal\WsfeFecaeProviderResultConvergenceContract;
use App\Domain\Fiscal\WsfeFecaeSoapResultData;
use App\Enums\FiscalAuthorizationOutcome;
use App\Enums\FiscalDocumentType;
use App\Enums\FiscalEnvironment;
use App\Enums\FiscalIntegrationMode;
use App\Enums\UserRole;
use App\Models\CommerceSale;
use App\Models\FiscalAuthorizationResponse;
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
use ReflectionClass;
use Tests\TestCase;

class WsfeProviderResultConvergenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            DatabaseSeeder::class
        );
    }

    public function test_convergence_is_explicit_provider_specific_contract(): void
    {
        $this->assertTrue(
            (
                new ReflectionClass(
                    WsfeFecaeProviderResultConvergenceContract::class
                )
            )->isInterface()
        );

        $this->assertInstanceOf(
            WsfeFecaeProviderResultConvergenceContract::class,
            new WsfeFecaeProviderResultConvergence
        );
    }

    public function test_authorized_wsfe_result_converges_without_losing_provider_evidence(): void
    {
        $raw = $this->approvedRaw();

        $result = $this->converge(
            $raw
        );

        $this->assertSame(
            FiscalAuthorizationOutcome::Authorized,
            $result->outcome
        );
        $this->assertSame(
            'A',
            $result->resultCode
        );
        $this->assertSame(
            '74123456789012',
            $result->authorizationCode
        );
        $this->assertSame(
            '2026-08-29',
            $result->authorizationCodeExpiresOn
        );
        $this->assertSame(
            'arca_wsfe_v1',
            $result->providerEvidence['provider']
        );
        $this->assertSame(
            'A',
            $result->providerEvidence[
                'header_result_code'
            ]
        );
        $this->assertSame(
            'A',
            $result->providerEvidence[
                'detail_result_code'
            ]
        );
        $this->assertSame(
            'approved with observation',
            $result->providerEvidence[
                'observations'
            ]['Obs'][0]['Msg']
        );
        $this->assertSame(
            $raw,
            $result->providerEvidence[
                'preserved_result'
            ]
        );
    }

    public function test_unknown_invalid_expiry_keeps_raw_evidence_but_not_invalid_neutral_date(): void
    {
        $raw = $this->approvedRaw();

        $raw['FeDetResp']
            ['FECAEDetResponse'][0]
            ['CAEFchVto'] =
                '20260231';

        $result = $this->converge(
            $raw
        );

        $this->assertSame(
            FiscalAuthorizationOutcome::Unknown,
            $result->outcome
        );
        $this->assertSame(
            '74123456789012',
            $result->authorizationCode
        );
        $this->assertNull(
            $result->authorizationCodeExpiresOn
        );
        $this->assertSame(
            '20260231',
            $result->providerEvidence[
                'preserved_result'
            ]['FeDetResp']
                ['FECAEDetResponse'][0]
                ['CAEFchVto']
        );
    }

    public function test_transport_result_remains_backward_compatible_without_provider_evidence(): void
    {
        $legacy = new FiscalAuthorizationTransportResult(
            FiscalAuthorizationOutcome::Unknown
        );

        $this->assertSame(
            FiscalAuthorizationOutcome::Unknown,
            $legacy->outcome
        );
        $this->assertNull(
            $legacy->resultCode
        );
        $this->assertNull(
            $legacy->authorizationCode
        );
        $this->assertNull(
            $legacy->authorizationCodeExpiresOn
        );
        $this->assertSame(
            [],
            $legacy->providerEvidence
        );
    }

    public function test_fact_bridge_persists_code_expiry_and_provider_evidence_idempotently(): void
    {
        $this->assertTrue(
            Schema::hasColumns(
                'fiscal_authorization_responses',
                [
                    'authorization_code',
                    'authorization_code_expires_on',
                    'provider_evidence',
                ]
            )
        );

        [$admin, $document] =
            $this->documentFixture();

        $transport = $this->converge(
            $this->approvedRaw()
        );

        $data =
            FiscalAuthorizationFactData::fromTransportResult(
                $document->id,
                $transport,
                'wsfe-provider-result-convergence-1'
            );

        $manager = app(
            FiscalAuthorizationFactManager::class
        );

        $attempt = $manager->record(
            $data,
            $admin
        );

        $again = $manager->record(
            $data,
            $admin
        );

        $response = $attempt
            ->response()
            ->firstOrFail();

        $this->assertSame(
            $attempt->id,
            $again->id
        );
        $this->assertSame(
            FiscalAuthorizationOutcome::Authorized,
            $response->outcome
        );
        $this->assertSame(
            'A',
            $response->result_code
        );
        $this->assertSame(
            '74123456789012',
            $response->authorization_code
        );
        $this->assertSame(
            '2026-08-29',
            $response
                ->authorization_code_expires_on
                ->format('Y-m-d')
        );
        $this->assertSame(
            'arca_wsfe_v1',
            $response->provider_evidence[
                'provider'
            ]
        );
        $this->assertSame(
            $transport->providerEvidence,
            $response->provider_evidence
        );
        $this->assertSame(
            1,
            FiscalAuthorizationResponse::query()
                ->count()
        );
    }

    public function test_idempotency_fingerprint_includes_provider_evidence(): void
    {
        [$admin, $document] =
            $this->documentFixture();

        $transport = $this->converge(
            $this->approvedRaw()
        );

        $key =
            'wsfe-provider-result-convergence-conflict';

        $manager = app(
            FiscalAuthorizationFactManager::class
        );

        $manager->record(
            FiscalAuthorizationFactData::fromTransportResult(
                $document->id,
                $transport,
                $key
            ),
            $admin
        );

        $changedEvidence =
            $transport->providerEvidence;

        $changedEvidence['events'] = [
            'Evt' => [
                [
                    'Code' => 999,
                    'Msg' =>
                        'different evidence',
                ],
            ],
        ];

        $this->expectException(
            DomainException::class
        );

        $manager->record(
            new FiscalAuthorizationFactData(
                fiscalDocumentId:
                    $document->id,
                outcome:
                    $transport->outcome,
                resultCode:
                    $transport->resultCode,
                idempotencyKey:
                    $key,
                authorizationCode:
                    $transport->authorizationCode,
                authorizationCodeExpiresOn:
                    $transport
                        ->authorizationCodeExpiresOn,
                providerEvidence:
                    $changedEvidence,
            ),
            $admin
        );
    }

    public function test_response_model_and_database_remain_immutable_after_evidence_extension(): void
    {
        [$admin, $document] =
            $this->documentFixture();

        $attempt = app(
            FiscalAuthorizationFactManager::class
        )->record(
            FiscalAuthorizationFactData::fromTransportResult(
                $document->id,
                $this->converge(
                    $this->approvedRaw()
                ),
                'wsfe-provider-result-convergence-immutable'
            ),
            $admin
        );

        $response = $attempt
            ->response()
            ->firstOrFail();

        try {
            $response->update([
                'authorization_code' =>
                    '99999999999999',
            ]);

            $this->fail(
                'La respuesta fiscal debía seguir siendo inmutable en el modelo.'
            );
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        try {
            DB::table(
                'fiscal_authorization_responses'
            )
                ->where(
                    'id',
                    $response->id
                )
                ->update([
                    'authorization_code' =>
                        '99999999999999',
                ]);

            $this->fail(
                'La respuesta fiscal debía seguir siendo inmutable en SQLite.'
            );
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    private function converge(
        array $raw
    ): FiscalAuthorizationTransportResult {
        $normalized = (
            new WsfeFecaeProviderResponseNormalizer
        )->normalize(
            new WsfeFecaeSoapResultData(
                $raw
            )
        );

        return (
            new WsfeFecaeProviderResultConvergence
        )->converge(
            $normalized
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function approvedRaw(): array
    {
        return [
            'FeCabResp' => [
                'Resultado' => 'A',
                'PtoVta' => 3,
                'CbteTipo' => 1,
            ],
            'FeDetResp' => [
                'FECAEDetResponse' => [
                    [
                        'Resultado' => 'A',
                        'CbteDesde' => 55,
                        'CbteHasta' => 55,
                        'CAE' =>
                            '74123456789012',
                        'CAEFchVto' =>
                            '20260829',
                        'Observaciones' => [
                            'Obs' => [
                                [
                                    'Code' =>
                                        100,
                                    'Msg' =>
                                        'approved with observation',
                                ],
                            ],
                        ],
                        'FutureDetailField' =>
                            'preserve-detail',
                    ],
                ],
            ],
            'Events' => [
                'Evt' => [
                    [
                        'Code' => 1,
                        'Msg' =>
                            'provider event',
                    ],
                ],
            ],
            'FutureTopLevel' => [
                'value' =>
                    'preserve-top',
            ],
        ];
    }

    /**
     * @return array{User,FiscalDocument}
     */
    private function documentFixture(): array
    {
        $organization =
            Organization::query()
                ->where(
                    'slug',
                    'sulu-tv'
                )
                ->firstOrFail();

        $admin = User::factory()
            ->create([
                'role' =>
                    UserRole::Admin,
                'current_organization_id' =>
                    $organization->id,
            ]);

        OrganizationMembership::withoutEvents(
            fn () =>
                OrganizationMembership::query()
                    ->updateOrCreate(
                        [
                            'organization_id' =>
                                $organization->id,
                            'user_id' =>
                                $admin->id,
                        ],
                        [
                            'role' =>
                                UserRole::Admin,
                            'active' =>
                                true,
                        ]
                    )
        );

        app(
            FiscalOrganizationProfileManager::class
        )->save(
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

        $point = app(
            FiscalPointOfSaleManager::class
        )->create(
            new FiscalPointOfSaleData(
                3,
                FiscalEnvironment::Homologation,
                FiscalIntegrationMode::WsfeV1
            ),
            $admin
        );

        DB::unprepared(
            'DROP TRIGGER IF EXISTS commerce_sales_guard_insert'
        );

        $sale = CommerceSale::query()
            ->create([
                'organization_id' =>
                    $organization->id,
                'sale_number' =>
                    9301,
                'status' =>
                    'building',
                'customer_name_snapshot' =>
                    'Receptor Fiscal',
                'currency_code' =>
                    'ARS',
                'service_subtotal_minor' =>
                    0,
                'product_subtotal_minor' =>
                    1210,
                'total_minor' =>
                    1210,
                'recorded_by_user_id' =>
                    $admin->id,
                'sold_at' =>
                    now(),
                'idempotency_key' =>
                    'provider-convergence-sale',
                'fingerprint' =>
                    hash(
                        'sha256',
                        'provider-convergence-sale'
                    ),
            ]);

        $document =
            FiscalDocument::query()
                ->create([
                    'organization_id' =>
                        $organization->id,
                    'fiscal_organization_profile_id' =>
                        $point
                            ->fiscal_organization_profile_id,
                    'fiscal_point_of_sale_id' =>
                        $point->id,
                    'commerce_sale_id' =>
                        $sale->id,
                    'document_type' =>
                        FiscalDocumentType::Invoice,
                    'issuer_snapshot' => [
                        'legal_name' =>
                            'Empresa Fiscal',
                    ],
                    'recipient_snapshot' => [
                        'name' =>
                            'Receptor Fiscal',
                    ],
                    'currency_code' =>
                        'ARS',
                    'service_subtotal_minor' =>
                        0,
                    'product_subtotal_minor' =>
                        1210,
                    'total_minor' =>
                        1210,
                    'documented_at' =>
                        now(),
                    'created_by_user_id' =>
                        $admin->id,
                    'idempotency_key' =>
                        'provider-convergence-document',
                    'fingerprint' =>
                        hash(
                            'sha256',
                            'provider-convergence-document'
                        ),
                ]);

        return [
            $admin,
            $document,
        ];
    }
}
