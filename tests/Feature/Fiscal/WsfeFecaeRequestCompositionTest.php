<?php

namespace Tests\Feature\Fiscal;

use App\Domain\Fiscal\FiscalAdjustmentData;
use App\Domain\Fiscal\FiscalAdjustmentLineData;
use App\Domain\Fiscal\FiscalAdjustmentManager;
use App\Domain\Fiscal\FiscalAssociatedVoucherData;
use App\Domain\Fiscal\FiscalDocumentAssociationData;
use App\Domain\Fiscal\FiscalDocumentAssociationManager;
use App\Domain\Fiscal\FiscalDocumentConceptData;
use App\Domain\Fiscal\FiscalDocumentConceptManager;
use App\Domain\Fiscal\FiscalDocumentCurrencyEvidenceData;
use App\Domain\Fiscal\FiscalDocumentCurrencyEvidenceManager;
use App\Domain\Fiscal\FiscalDocumentIssueDateData;
use App\Domain\Fiscal\FiscalDocumentIssueDateManager;
use App\Domain\Fiscal\FiscalDocumentMonetarySummaryData;
use App\Domain\Fiscal\FiscalDocumentMonetarySummaryManager;
use App\Domain\Fiscal\FiscalDocumentPaymentDueDateData;
use App\Domain\Fiscal\FiscalDocumentPaymentDueDateManager;
use App\Domain\Fiscal\FiscalDocumentRecipientEvidenceData;
use App\Domain\Fiscal\FiscalDocumentRecipientEvidenceManager;
use App\Domain\Fiscal\FiscalOrganizationProfileData;
use App\Domain\Fiscal\FiscalOrganizationProfileManager;
use App\Domain\Fiscal\FiscalPointOfSaleData;
use App\Domain\Fiscal\FiscalPointOfSaleManager;
use App\Domain\Fiscal\FiscalTaxCompositionData;
use App\Domain\Fiscal\FiscalTaxCompositionManager;
use App\Domain\Fiscal\FiscalTaxWsfeClassificationData;
use App\Domain\Fiscal\FiscalTaxWsfeClassificationManager;
use App\Domain\Fiscal\FiscalTaxWsfeIdentityData;
use App\Domain\Fiscal\FiscalVoucherClassificationData;
use App\Domain\Fiscal\FiscalVoucherClassificationManager;
use App\Domain\Fiscal\WsfeFecaeRequestComposer;
use App\Enums\CommerceSaleLineType;
use App\Enums\FiscalDocumentConcept;
use App\Enums\FiscalDocumentType;
use App\Enums\FiscalEnvironment;
use App\Enums\FiscalIntegrationMode;
use App\Enums\FiscalTaxWsfeBucket;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WsfeFecaeRequestCompositionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_complete_explicit_evidence_composes_one_standard_fecae_request(): void
    {
        [$admin, $document] = $this->completeCreditNote(
            key: 'full',
            voucherCode: '3'
        );

        $request = app(
            WsfeFecaeRequestComposer::class
        )->compose(
            $document,
            42
        );

        $this->assertSame(
            [
                'CantReg' => 1,
                'PtoVta' => 930,
                'CbteTipo' => 3,
            ],
            $request->header->toWsfeArray()
        );

        $this->assertSame(
            [
                'Concepto' => 3,
                'DocTipo' => 80,
                'DocNro' => '27111111111',
                'CbteDesde' => 42,
                'CbteHasta' => 42,
                'CbteFch' => '20260818',
                'ImpTotal' => '123.00',
                'ImpTotConc' => '0.00',
                'ImpNeto' => '100.00',
                'ImpOpEx' => '0.00',
                'ImpTrib' => '2.00',
                'ImpIVA' => '21.00',
                'FchServDesde' => '20260801',
                'FchServHasta' => '20260810',
                'FchVtoPago' => '20260825',
                'MonId' => 'PES',
                'MonCotiz' => '1.000000',
                'CanMisMonExt' => 'N',
                'CondicionIVAReceptorId' => 1,
                'CbtesAsoc' => [
                    'CbteAsoc' => [
                        [
                            'Tipo' => 1,
                            'PtoVta' => 930,
                            'Nro' => 77,
                            'Cuit' => '20123456786',
                            'CbteFch' => '20260817',
                        ],
                    ],
                ],
                'Tributos' => [
                    'Tributo' => [
                        [
                            'Id' => 99,
                            'Desc' => 'Impuesto Municipal',
                            'BaseImp' => '100.00',
                            'Alic' => '2.00',
                            'Importe' => '2.00',
                        ],
                    ],
                ],
                'Iva' => [
                    'AlicIva' => [
                        [
                            'Id' => 5,
                            'BaseImp' => '100.00',
                            'Importe' => '21.00',
                        ],
                    ],
                ],
            ],
            $request->detail->toWsfeArray()
        );

        $this->assertSame(
            [
                'FeCabReq' =>
                    $request->header->toWsfeArray(),
                'FeDetReq' => [
                    'FECAEDetRequest' => [
                        $request->detail->toWsfeArray(),
                    ],
                ],
            ],
            $request->toWsfeArray()
        );

        $this->assertSame(
            $document->id,
            $document->id
        );

        $this->assertNotNull($admin);
    }

    public function test_composer_never_uses_local_number_assignment_as_cbte_number(): void
    {
        [, $document] = $this->completeCreditNote(
            key: 'remote-number',
            voucherCode: '3'
        );

        $request = app(
            WsfeFecaeRequestComposer::class
        )->compose(
            $document,
            54321
        );

        $detail =
            $request->detail->toWsfeArray();

        $this->assertSame(
            54321,
            $detail['CbteDesde']
        );

        $this->assertSame(
            54321,
            $detail['CbteHasta']
        );

        $this->assertFalse(
            array_key_exists(
                'assignedNumber',
                $detail
            )
        );
    }

    public function test_missing_explicit_tax_identity_fails_closed(): void
    {
        [$admin, $document] =
            $this->baseCreditNote(
                key: 'missing-tax-identity',
                voucherCode: '3'
            );

        $this->addCoreEvidence(
            $admin,
            $document,
            addTaxIdentity: false
        );

        $this->expectException(
            DomainException::class
        );

        app(
            WsfeFecaeRequestComposer::class
        )->compose(
            $document,
            1
        );
    }

    public function test_fce_codes_remain_fail_closed_before_transport_serialization(): void
    {
        [$admin, $document] =
            $this->baseCreditNote(
                key: 'fce',
                voucherCode: '203'
            );

        $this->addCoreEvidence(
            $admin,
            $document,
            addAssociation: false,
            addTaxIdentity: true
        );

        $this->expectException(
            DomainException::class
        );

        app(
            WsfeFecaeRequestComposer::class
        )->compose(
            $document,
            1
        );
    }

    public function test_iva_same_id_is_aggregated_without_binary_float_math(): void
    {
        [$admin, $document] =
            $this->baseCreditNote(
                key: 'aggregate-iva',
                voucherCode: '3',
                totalMinor: 12300
            );

        $this->addNonTaxEvidence(
            $admin,
            $document
        );

        app(
            FiscalTaxCompositionManager::class
        )->record(
            new FiscalTaxCompositionData(
                $document->id,
                [
                    [
                        'tax_code' => 'iva-part-a',
                        'taxable_base_minor' => 6000,
                        'rate_basis_points' => 2100,
                        'tax_amount_minor' => 1260,
                    ],
                    [
                        'tax_code' => 'iva-part-b',
                        'taxable_base_minor' => 4000,
                        'rate_basis_points' => 2100,
                        'tax_amount_minor' => 840,
                    ],
                    [
                        'tax_code' => 'tribute',
                        'taxable_base_minor' => 10000,
                        'rate_basis_points' => 200,
                        'tax_amount_minor' => 200,
                    ],
                ],
                'fecae-tax:aggregate-iva'
            ),
            $admin
        );

        app(
            FiscalDocumentMonetarySummaryManager::class
        )->record(
            new FiscalDocumentMonetarySummaryData(
                $document->id,
                0,
                10000,
                0,
                200,
                2100
            ),
            $admin
        );

        $components =
            $document->refresh()->taxComponents;

        app(
            FiscalTaxWsfeClassificationManager::class
        )->record(
            new FiscalTaxWsfeClassificationData(
                $document->id,
                [
                    new FiscalTaxWsfeIdentityData(
                        $components[0]->id,
                        FiscalTaxWsfeBucket::Iva,
                        5
                    ),
                    new FiscalTaxWsfeIdentityData(
                        $components[1]->id,
                        FiscalTaxWsfeBucket::Iva,
                        5
                    ),
                    new FiscalTaxWsfeIdentityData(
                        $components[2]->id,
                        FiscalTaxWsfeBucket::Tributo,
                        99,
                        'Impuesto Municipal'
                    ),
                ]
            ),
            $admin
        );

        $this->addAssociation(
            $admin,
            $document,
            'aggregate-iva'
        );

        $detail = app(
            WsfeFecaeRequestComposer::class
        )->compose(
            $document,
            55
        )->detail->toWsfeArray();

        $this->assertSame(
            [
                [
                    'Id' => 5,
                    'BaseImp' => '100.00',
                    'Importe' => '21.00',
                ],
            ],
            $detail['Iva']['AlicIva']
        );
    }

    private function completeCreditNote(
        string $key,
        string $voucherCode
    ): array {
        [$admin, $document] =
            $this->baseCreditNote(
                key: $key,
                voucherCode: $voucherCode
            );

        $this->addCoreEvidence(
            $admin,
            $document
        );

        return [
            $admin,
            $document->refresh(),
        ];
    }

    private function baseCreditNote(
        string $key,
        string $voucherCode,
        int $totalMinor = 12300
    ): array {
        $organization = Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();

        $admin = User::query()
            ->where(
                'email',
                'test@example.com'
            )
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
                930,
                FiscalEnvironment::Homologation,
                FiscalIntegrationMode::WsfeV1
            ),
            $admin
        );

        $service = 4000;
        $product = $totalMinor - $service;

        $document = app(
            FiscalAdjustmentManager::class
        )->record(
            new FiscalAdjustmentData(
                $point->id,
                FiscalDocumentType::CreditNote,
                ['name' => 'Receptor Fiscal'],
                'ARS',
                $service,
                $product,
                $totalMinor,
                [
                    new FiscalAdjustmentLineData(
                        1,
                        CommerceSaleLineType::Service,
                        'Servicio explícito',
                        '1',
                        $service,
                        $service
                    ),
                    new FiscalAdjustmentLineData(
                        2,
                        CommerceSaleLineType::Product,
                        'Producto explícito',
                        '1',
                        $product,
                        $product
                    ),
                ],
                'fecae-document:'.$key
            ),
            $admin
        );

        app(
            FiscalVoucherClassificationManager::class
        )->classify(
            new FiscalVoucherClassificationData(
                $document->id,
                'A',
                $voucherCode
            ),
            $admin
        );

        return [$admin, $document];
    }

    private function addCoreEvidence(
        User $admin,
        $document,
        bool $addAssociation = true,
        bool $addTaxIdentity = true
    ): void {
        $this->addNonTaxEvidence(
            $admin,
            $document
        );

        app(
            FiscalTaxCompositionManager::class
        )->record(
            new FiscalTaxCompositionData(
                $document->id,
                [
                    [
                        'tax_code' => 'iva',
                        'taxable_base_minor' => 10000,
                        'rate_basis_points' => 2100,
                        'tax_amount_minor' => 2100,
                    ],
                    [
                        'tax_code' => 'municipal',
                        'taxable_base_minor' => 10000,
                        'rate_basis_points' => 200,
                        'tax_amount_minor' => 200,
                    ],
                ],
                'fecae-tax:'.$document->id
            ),
            $admin
        );

        app(
            FiscalDocumentMonetarySummaryManager::class
        )->record(
            new FiscalDocumentMonetarySummaryData(
                $document->id,
                0,
                10000,
                0,
                200,
                2100
            ),
            $admin
        );

        if ($addTaxIdentity) {
            $components =
                $document->refresh()->taxComponents;

            app(
                FiscalTaxWsfeClassificationManager::class
            )->record(
                new FiscalTaxWsfeClassificationData(
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
                ),
                $admin
            );
        }

        if ($addAssociation) {
            $this->addAssociation(
                $admin,
                $document,
                (string) $document->id
            );
        }
    }

    private function addNonTaxEvidence(
        User $admin,
        $document
    ): void {
        app(
            FiscalDocumentConceptManager::class
        )->record(
            new FiscalDocumentConceptData(
                $document->id,
                FiscalDocumentConcept::ProductsAndServices,
                CarbonImmutable::parse('2026-08-01'),
                CarbonImmutable::parse('2026-08-10')
            ),
            $admin
        );

        app(
            FiscalDocumentRecipientEvidenceManager::class
        )->record(
            new FiscalDocumentRecipientEvidenceData(
                $document->id,
                '80',
                '27-11111111-1',
                '1'
            ),
            $admin
        );

        app(
            FiscalDocumentIssueDateManager::class
        )->record(
            new FiscalDocumentIssueDateData(
                $document->id,
                CarbonImmutable::parse('2026-08-18')
            ),
            $admin
        );

        app(
            FiscalDocumentCurrencyEvidenceManager::class
        )->record(
            new FiscalDocumentCurrencyEvidenceData(
                $document->id,
                'ARS',
                'PES',
                1_000_000,
                false
            ),
            $admin
        );

        app(
            FiscalDocumentPaymentDueDateManager::class
        )->record(
            new FiscalDocumentPaymentDueDateData(
                $document->id,
                CarbonImmutable::parse('2026-08-25')
            ),
            $admin
        );
    }

    private function addAssociation(
        User $admin,
        $document,
        string $key
    ): void {
        app(
            FiscalDocumentAssociationManager::class
        )->record(
            new FiscalDocumentAssociationData(
                $document->id,
                FiscalDocumentAssociationManager::MODE_VOUCHERS,
                [
                    new FiscalAssociatedVoucherData(
                        1,
                        930,
                        77,
                        '20123456786',
                        CarbonImmutable::parse(
                            '2026-08-17'
                        )
                    ),
                ]
            ),
            $admin
        );

        $this->assertNotSame('', $key);
    }
}
