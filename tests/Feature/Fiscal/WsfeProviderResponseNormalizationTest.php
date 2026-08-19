<?php

namespace Tests\Feature\Fiscal;

use App\Domain\Fiscal\WsfeFecaeNormalizedResponseData;
use App\Domain\Fiscal\WsfeFecaeProviderResponseNormalizer;
use App\Domain\Fiscal\WsfeFecaeProviderResponseNormalizerContract;
use App\Domain\Fiscal\WsfeFecaeSoapResultData;
use App\Enums\FiscalAuthorizationOutcome;
use DomainException;
use ReflectionClass;
use Tests\TestCase;

class WsfeProviderResponseNormalizationTest extends TestCase
{
    public function test_normalizer_is_explicit_contract(): void
    {
        $this->assertTrue(
            (
                new ReflectionClass(
                    WsfeFecaeProviderResponseNormalizerContract::class
                )
            )->isInterface()
        );

        $this->assertInstanceOf(
            WsfeFecaeProviderResponseNormalizerContract::class,
            new WsfeFecaeProviderResponseNormalizer
        );
    }

    public function test_approved_detail_with_cae_expiry_and_observation_normalizes_authorized(): void
    {
        $raw = $this->approvedRaw();

        $normalized = $this->normalize(
            $raw
        );

        $this->assertInstanceOf(
            WsfeFecaeNormalizedResponseData::class,
            $normalized
        );
        $this->assertSame(
            FiscalAuthorizationOutcome::Authorized,
            $normalized->outcome
        );
        $this->assertSame(
            'A',
            $normalized->headerResultCode
        );
        $this->assertSame(
            'A',
            $normalized->detailResultCode
        );
        $this->assertSame(
            '74123456789012',
            $normalized->cae
        );
        $this->assertSame(
            '20260829',
            $normalized->caeExpiration
        );
        $this->assertSame(
            'approved with observation',
            $normalized->observations
                ['Obs'][0]['Msg']
        );
        $this->assertSame(
            $raw,
            $normalized->preservedResult()
        );
        $this->assertSame(
            'preserve-top',
            $normalized->preservedResult()
                ['FutureTopLevel']['value']
        );
    }

    public function test_rejected_detail_without_cae_normalizes_rejected_and_preserves_errors(): void
    {
        $raw = [
            'FeCabResp' => [
                'Resultado' => 'R',
            ],
            'FeDetResp' => [
                'FECAEDetResponse' => [
                    'Resultado' => 'R',
                    'CAE' => '',
                    'Observaciones' => [
                        'Obs' => [
                            [
                                'Code' => 10001,
                                'Msg' => 'provider rejection evidence',
                            ],
                        ],
                    ],
                ],
            ],
            'Errors' => [
                'Err' => [
                    [
                        'Code' => 600,
                        'Msg' => 'provider error evidence',
                    ],
                ],
            ],
        ];

        $normalized = $this->normalize(
            $raw
        );

        $this->assertSame(
            FiscalAuthorizationOutcome::Rejected,
            $normalized->outcome
        );
        $this->assertSame(
            'R',
            $normalized->headerResultCode
        );
        $this->assertSame(
            'R',
            $normalized->detailResultCode
        );
        $this->assertNull(
            $normalized->cae
        );
        $this->assertNull(
            $normalized->caeExpiration
        );
        $this->assertSame(
            'provider error evidence',
            $normalized->errors
                ['Err'][0]['Msg']
        );
    }

    public function test_provider_errors_without_authorization_shape_remain_unknown(): void
    {
        $normalized = $this->normalize(
            [
                'Errors' => [
                    'Err' => [
                        [
                            'Code' => 500,
                            'Msg' => 'request level error',
                        ],
                    ],
                ],
            ]
        );

        $this->assertSame(
            FiscalAuthorizationOutcome::Unknown,
            $normalized->outcome
        );
        $this->assertNull(
            $normalized->headerResultCode
        );
        $this->assertNull(
            $normalized->detailResultCode
        );
        $this->assertNull(
            $normalized->cae
        );
        $this->assertSame(
            500,
            $normalized->errors
                ['Err'][0]['Code']
        );
    }

    public function test_unknown_partial_or_contradictory_results_fail_closed_to_unknown(): void
    {
        foreach (
            [
                [
                    'FeCabResp' => [
                        'Resultado' => 'P',
                    ],
                    'FeDetResp' => [
                        'FECAEDetResponse' => [
                            'Resultado' => 'A',
                            'CAE' => '74123456789012',
                            'CAEFchVto' => '20260829',
                        ],
                    ],
                ],
                [
                    'FeCabResp' => [
                        'Resultado' => 'A',
                    ],
                    'FeDetResp' => [
                        'FECAEDetResponse' => [
                            'Resultado' => 'A',
                            'CAE' => '',
                            'CAEFchVto' => '20260829',
                        ],
                    ],
                ],
                [
                    'FeCabResp' => [
                        'Resultado' => 'A',
                    ],
                    'FeDetResp' => [
                        'FECAEDetResponse' => [
                            'Resultado' => 'R',
                        ],
                    ],
                ],
                [
                    'FeCabResp' => [
                        'Resultado' => 'R',
                    ],
                    'FeDetResp' => [
                        'FECAEDetResponse' => [
                            'Resultado' => 'R',
                            'CAE' => '74123456789012',
                        ],
                    ],
                ],
                [
                    'FeCabResp' => [
                        'Resultado' => 'X',
                    ],
                    'FeDetResp' => [
                        'FECAEDetResponse' => [
                            'Resultado' => 'X',
                        ],
                    ],
                ],
            ]
            as $raw
        ) {
            $this->assertSame(
                FiscalAuthorizationOutcome::Unknown,
                $this->normalize($raw)->outcome
            );
        }
    }

    public function test_invalid_cae_expiry_keeps_response_unknown_without_dropping_evidence(): void
    {
        $raw = $this->approvedRaw();

        $raw['FeDetResp']
            ['FECAEDetResponse'][0]
            ['CAEFchVto'] = '20260231';

        $normalized = $this->normalize(
            $raw
        );

        $this->assertSame(
            FiscalAuthorizationOutcome::Unknown,
            $normalized->outcome
        );
        $this->assertSame(
            '20260231',
            $normalized->caeExpiration
        );
        $this->assertSame(
            $raw,
            $normalized->preservedResult()
        );
    }

    public function test_single_associative_detail_and_single_item_list_are_both_normalized(): void
    {
        $listRaw = $this->approvedRaw();

        $assocRaw = $listRaw;
        $assocRaw['FeDetResp']
            ['FECAEDetResponse'] =
            $assocRaw['FeDetResp']
                ['FECAEDetResponse'][0];

        $this->assertSame(
            FiscalAuthorizationOutcome::Authorized,
            $this->normalize($listRaw)->outcome
        );
        $this->assertSame(
            FiscalAuthorizationOutcome::Authorized,
            $this->normalize($assocRaw)->outcome
        );
    }

    public function test_multiple_detail_responses_are_rejected_by_v1_boundary(): void
    {
        $raw = $this->approvedRaw();

        $raw['FeDetResp']
            ['FECAEDetResponse'][] =
            $raw['FeDetResp']
                ['FECAEDetResponse'][0];

        $this->expectException(
            DomainException::class
        );

        $this->normalize(
            $raw
        );
    }

    public function test_malformed_nested_provider_sections_are_rejected(): void
    {
        foreach (
            [
                [
                    'FeCabResp' => [
                        'Resultado' => 'A',
                    ],
                    'FeDetResp' => [
                        'FECAEDetResponse' =>
                            'not-an-array',
                    ],
                ],
                [
                    'FeCabResp' => [
                        'Resultado' => [
                            'not-scalar',
                        ],
                    ],
                ],
                [
                    'FeCabResp' => [
                        'Resultado' => 'A',
                    ],
                    'FeDetResp' => [
                        'FECAEDetResponse' => [
                            'Resultado' => 'A',
                            'CAE' => '74123456789012',
                            'CAEFchVto' => '20260829',
                            'Observaciones' =>
                                'not-an-array',
                        ],
                    ],
                ],
            ]
            as $raw
        ) {
            try {
                $this->normalize(
                    $raw
                );

                $this->fail(
                    'La forma provider inválida debía fallar.'
                );
            } catch (DomainException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_normalization_does_not_modify_transport_result_or_interpret_provider_codes(): void
    {
        $normalizerSource = file_get_contents(
            (string) (
                new ReflectionClass(
                    WsfeFecaeProviderResponseNormalizer::class
                )
            )->getFileName()
        );

        $this->assertIsString(
            $normalizerSource
        );

        foreach (
            [
                'FiscalAuthorizationTransportResult',
                'SoapClient',
                'Http::',
                'curl_',
                'DOMDocument',
                'XMLWriter',
                'Err.Code',
                'Evt.Code',
                'Obs.Code',
            ]
            as $forbidden
        ) {
            $this->assertStringNotContainsString(
                $forbidden,
                $normalizerSource
            );
        }
    }

    private function normalize(
        array $raw
    ): WsfeFecaeNormalizedResponseData {
        return (
            new WsfeFecaeProviderResponseNormalizer
        )->normalize(
            new WsfeFecaeSoapResultData(
                $raw
            )
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
                        'CAE' => '74123456789012',
                        'CAEFchVto' => '20260829',
                        'Observaciones' => [
                            'Obs' => [
                                [
                                    'Code' => 100,
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
                        'Msg' => 'provider event',
                    ],
                ],
            ],
            'FutureTopLevel' => [
                'value' => 'preserve-top',
            ],
        ];
    }
}
