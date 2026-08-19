<?php

namespace Tests\Feature\Fiscal;

use App\Domain\Fiscal\FiscalAuthorizationTransportRequest;
use App\Domain\Fiscal\WsaaAccessTicket;
use App\Domain\Fiscal\WsaaAccessTicketRequest;
use App\Domain\Fiscal\WsfeFecaeDetailData;
use App\Domain\Fiscal\WsfeFecaeHeaderData;
use App\Domain\Fiscal\WsfeFecaeRequestData;
use App\Domain\Fiscal\WsfeFecaeSoap11Call;
use App\Domain\Fiscal\WsfeFecaeSoapResultData;
use App\Enums\FiscalEnvironment;
use Carbon\CarbonImmutable;
use DomainException;
use ReflectionClass;
use Tests\TestCase;

class WsfeSoapSerializationBoundaryTest extends TestCase
{
    public function test_call_exposes_exact_soap11_operation_metadata_and_parameters(): void
    {
        $call = new WsfeFecaeSoap11Call(
            $this->transportRequest(),
            $this->ticketRequest(),
            $this->ticket(),
            $this->liveInstant()
        );

        $this->assertSame(
            'FECAESolicitar',
            WsfeFecaeSoap11Call::OPERATION
        );
        $this->assertSame(
            'http://ar.gov.afip.dif.FEV1/',
            WsfeFecaeSoap11Call::SERVICE_NAMESPACE
        );
        $this->assertSame(
            'http://schemas.xmlsoap.org/soap/envelope/',
            WsfeFecaeSoap11Call::ENVELOPE_NAMESPACE
        );
        $this->assertSame(
            'text/xml; charset=utf-8',
            WsfeFecaeSoap11Call::CONTENT_TYPE
        );
        $this->assertSame(
            'http://ar.gov.afip.dif.FEV1/FECAESolicitar',
            WsfeFecaeSoap11Call::SOAP_ACTION
        );

        $parameters = $call->operationParameters();

        $this->assertSame(
            [
                'Token' => 'secret-token',
                'Sign' => 'secret-sign',
                'Cuit' => '20123456786',
            ],
            $parameters['Auth']
        );

        $this->assertSame(
            $this->fecaeRequest()->toWsfeArray(),
            $parameters['FeCAEReq']
        );

        $this->assertSame(
            [
                'FeCabReq',
                'FeDetReq',
            ],
            array_keys($parameters['FeCAEReq'])
        );
    }

    public function test_call_rejects_ticket_scope_that_does_not_match_transport(): void
    {
        $this->expectException(
            DomainException::class
        );

        new WsfeFecaeSoap11Call(
            $this->transportRequest(),
            new WsaaAccessTicketRequest(
                92,
                FiscalEnvironment::Homologation,
                'wsfe-explicit',
                '20123456786'
            ),
            $this->ticket(),
            $this->liveInstant()
        );
    }

    public function test_call_rejects_expired_ticket(): void
    {
        $this->expectException(
            DomainException::class
        );

        new WsfeFecaeSoap11Call(
            $this->transportRequest(),
            $this->ticketRequest(),
            $this->ticket(),
            CarbonImmutable::parse(
                '2026-08-19T10:00:00-03:00'
            )
        );
    }

    public function test_call_rejects_transport_and_fecae_sequence_mismatch(): void
    {
        $request = new FiscalAuthorizationTransportRequest(
            organizationId: 91,
            fiscalDocumentId: 501,
            environment:
                FiscalEnvironment::Homologation,
            pointOfSaleNumber: 3,
            voucherTypeCode: 1,
            voucherNumber: 56,
            fecaeRequest: $this->fecaeRequest(),
        );

        $this->expectException(
            DomainException::class
        );

        new WsfeFecaeSoap11Call(
            $request,
            $this->ticketRequest(),
            $this->ticket(),
            $this->liveInstant()
        );
    }

    public function test_secret_containing_call_cannot_be_serialized_or_dumped_with_secrets(): void
    {
        $call = new WsfeFecaeSoap11Call(
            $this->transportRequest(),
            $this->ticketRequest(),
            $this->ticket(),
            $this->liveInstant()
        );

        $publicProperties = (
            new ReflectionClass(
                WsfeFecaeSoap11Call::class
            )
        )->getProperties(
            \ReflectionProperty::IS_PUBLIC
        );

        $this->assertCount(
            0,
            $publicProperties
        );

        $json = json_encode(
            $call,
            JSON_THROW_ON_ERROR
        );

        $this->assertStringNotContainsString(
            'secret-token',
            $json
        );
        $this->assertStringNotContainsString(
            'secret-sign',
            $json
        );

        ob_start();
        var_dump($call);
        $debug = (string) ob_get_clean();

        $this->assertStringNotContainsString(
            'secret-token',
            $debug
        );
        $this->assertStringNotContainsString(
            'secret-sign',
            $debug
        );
        $this->assertStringContainsString(
            '[REDACTED]',
            $debug
        );

        try {
            serialize($call);
            $this->fail(
                'La llamada con secretos no debe serializarse.'
            );
        } catch (DomainException) {
            $this->assertTrue(true);
        }
    }

    public function test_provider_result_preserves_cae_expiry_observations_events_errors_and_unknown_fields(): void
    {
        $raw = [
            'FeCabResp' => [
                'Cuit' => '20123456786',
                'PtoVta' => 3,
                'CbteTipo' => 1,
                'Resultado' => 'A',
            ],
            'FeDetResp' => [
                'FECAEDetResponse' => [
                    [
                        'Resultado' => 'A',
                        'CAE' => '74123456789012',
                        'CAEFchVto' => '20260829',
                        'Observaciones' => [
                            'Obs' => [
                                [
                                    'Code' => 100,
                                    'Msg' => 'provider observation',
                                ],
                            ],
                        ],
                        'FutureCompatibleField' =>
                            'preserve-me',
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
            'Errors' => [
                'Err' => [
                    [
                        'Code' => 2,
                        'Msg' => 'provider error',
                    ],
                ],
            ],
            'FutureTopLevel' => [
                'value' => 'keep-me',
            ],
        ];

        $result = new WsfeFecaeSoapResultData(
            $raw
        );

        $this->assertSame(
            $raw,
            $result->preservedResult()
        );
        $this->assertSame(
            $raw['FeCabResp'],
            $result->header()
        );
        $this->assertSame(
            $raw['FeDetResp'],
            $result->detailSection()
        );
        $this->assertSame(
            '74123456789012',
            $result->detailSection()
                ['FECAEDetResponse'][0]['CAE']
        );
        $this->assertSame(
            '20260829',
            $result->detailSection()
                ['FECAEDetResponse'][0]['CAEFchVto']
        );
        $this->assertSame(
            'provider observation',
            $result->detailSection()
                ['FECAEDetResponse'][0]
                ['Observaciones']['Obs'][0]['Msg']
        );
        $this->assertSame(
            'preserve-me',
            $result->preservedResult()
                ['FeDetResp']['FECAEDetResponse'][0]
                ['FutureCompatibleField']
        );
        $this->assertSame(
            $raw['Events'],
            $result->events()
        );
        $this->assertSame(
            $raw['Errors'],
            $result->errors()
        );
        $this->assertSame(
            'keep-me',
            $result->preservedResult()
                ['FutureTopLevel']['value']
        );
    }

    public function test_provider_result_fails_closed_on_empty_or_malformed_known_sections(): void
    {
        foreach (
            [
                [],
                ['FeCabResp' => 'not-an-array'],
                ['UnknownOnly' => ['x' => 'y']],
            ]
            as $invalid
        ) {
            try {
                new WsfeFecaeSoapResultData(
                    $invalid
                );

                $this->fail(
                    'El resultado provider inválido debía fallar.'
                );
            } catch (DomainException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_new_production_classes_have_no_network_or_xml_wire_implementation(): void
    {
        foreach (
            [
                WsfeFecaeSoap11Call::class,
                WsfeFecaeSoapResultData::class,
            ]
            as $class
        ) {
            $source = file_get_contents(
                (string) (
                    new ReflectionClass($class)
                )->getFileName()
            );

            $this->assertIsString($source);

            foreach (
                [
                    'SoapClient',
                    'Http::',
                    'curl_',
                    'DOMDocument',
                    'XMLWriter',
                    'SimpleXMLElement',
                    '__soapCall',
                ]
                as $forbidden
            ) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $source
                );
            }
        }
    }

    private function transportRequest(): FiscalAuthorizationTransportRequest
    {
        return new FiscalAuthorizationTransportRequest(
            organizationId: 91,
            fiscalDocumentId: 501,
            environment:
                FiscalEnvironment::Homologation,
            pointOfSaleNumber: 3,
            voucherTypeCode: 1,
            voucherNumber: 55,
            fecaeRequest: $this->fecaeRequest(),
        );
    }

    private function ticketRequest(): WsaaAccessTicketRequest
    {
        return new WsaaAccessTicketRequest(
            91,
            FiscalEnvironment::Homologation,
            'wsfe-explicit',
            '20123456786'
        );
    }

    private function ticket(): WsaaAccessTicket
    {
        return new WsaaAccessTicket(
            91,
            FiscalEnvironment::Homologation,
            'wsfe-explicit',
            '20123456786',
            'secret-token',
            'secret-sign',
            CarbonImmutable::parse(
                '2026-08-19T08:00:00-03:00'
            ),
            CarbonImmutable::parse(
                '2026-08-19T10:00:00-03:00'
            )
        );
    }

    private function liveInstant(): CarbonImmutable
    {
        return CarbonImmutable::parse(
            '2026-08-19T09:00:00-03:00'
        );
    }

    private function fecaeRequest(): WsfeFecaeRequestData
    {
        return new WsfeFecaeRequestData(
            new WsfeFecaeHeaderData(
                recordCount: 1,
                pointOfSaleNumber: 3,
                voucherTypeCode: 1,
            ),
            new WsfeFecaeDetailData(
                conceptCode: 1,
                documentTypeCode: 80,
                documentNumber: '20123456786',
                voucherFrom: 55,
                voucherTo: 55,
                voucherDate: '20260819',
                totalAmount: '121.00',
                nonTaxedAmount: '0.00',
                netTaxableAmount: '100.00',
                exemptAmount: '0.00',
                tributesAmount: '0.00',
                vatAmount: '21.00',
                serviceFrom: null,
                serviceTo: null,
                paymentDueDate: null,
                currencyId: 'PES',
                currencyQuotation: '1.000000',
                sameCurrencySettlement: 'N',
                recipientVatConditionId: 1,
                associatedVouchers: [],
                associatedPeriod: null,
                tributes: [],
                vat: [
                    [
                        'Id' => 5,
                        'BaseImp' => '100.00',
                        'Importe' => '21.00',
                    ],
                ],
            )
        );
    }
}
