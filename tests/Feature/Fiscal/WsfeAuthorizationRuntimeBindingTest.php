<?php

namespace Tests\Feature\Fiscal;

use App\Adapters\Fiscal\Arca\EnvironmentFiscalAuthorizationRuntimeScopeStore;
use App\Adapters\Fiscal\Arca\EnvironmentWsaaCredentialMaterialReferenceStore;
use App\Adapters\Fiscal\Arca\WsaaBackedFiscalAuthorizationTransport;
use App\Adapters\Fiscal\Arca\WsaaBackedFiscalRemoteSequenceAuthority;
use App\Domain\Fiscal\ArcaFiscalAuthorizationAdapter;
use App\Domain\Fiscal\ArcaHomologationReadiness;
use App\Domain\Fiscal\FiscalAuthorizationCredentialStore;
use App\Domain\Fiscal\FiscalAuthorizationRuntimeScopeStore;
use App\Domain\Fiscal\FiscalAuthorizationTransport;
use App\Domain\Fiscal\FiscalAuthorizationTransportRequest;
use App\Domain\Fiscal\FiscalRemoteSequenceAuthority;
use App\Domain\Fiscal\FiscalRemoteSequenceQuery;
use App\Domain\Fiscal\FiscalRemoteSequenceState;
use App\Domain\Fiscal\WsaaAccessTicket;
use App\Domain\Fiscal\WsaaAccessTicketProvider;
use App\Domain\Fiscal\WsaaAccessTicketRequest;
use App\Domain\Fiscal\WsaaCredentialMaterialReference;
use App\Domain\Fiscal\WsaaCredentialMaterialReferenceStore;
use App\Domain\Fiscal\WsaaTraClock;
use App\Domain\Fiscal\WsfeCompUltimoAutorizadoSoap11Call;
use App\Domain\Fiscal\WsfeCompUltimoAutorizadoSoapTransport;
use App\Domain\Fiscal\WsfeFecaeDetailData;
use App\Domain\Fiscal\WsfeFecaeHeaderData;
use App\Domain\Fiscal\WsfeFecaeProviderResponseNormalizer;
use App\Domain\Fiscal\WsfeFecaeProviderResultConvergence;
use App\Domain\Fiscal\WsfeFecaeRequestComposer;
use App\Domain\Fiscal\WsfeFecaeRequestComposerContract;
use App\Domain\Fiscal\WsfeFecaeRequestData;
use App\Domain\Fiscal\WsfeFecaeSoap11Call;
use App\Domain\Fiscal\WsfeFecaeSoapResultData;
use App\Domain\Fiscal\WsfeFecaeSoapTransport;
use App\Enums\FiscalAuthorizationOutcome;
use App\Enums\FiscalEnvironment;
use Carbon\CarbonImmutable;
use DomainException;
use Tests\TestCase;

class WsfeAuthorizationRuntimeBindingTest extends TestCase
{
    private mixed $previousReferenceMap = null;

    private bool $referenceMapExisted = false;

    protected function setUp(): void
    {
        parent::setUp();

        $key = EnvironmentWsaaCredentialMaterialReferenceStore::ENVIRONMENT_KEY;
        $this->referenceMapExisted = array_key_exists($key, $_SERVER);
        $this->previousReferenceMap = $_SERVER[$key] ?? null;

        $_SERVER[$key] = json_encode([
            '7' => [
                'service' => 'wsfe',
                'issuer_cuit' => '20123456789',
                'certificate_reference' => 'file:synthetic/cert.pem',
                'private_key_reference' => 'file:synthetic/key.pem',
                'private_key_passphrase_reference' => null,
            ],
        ], JSON_THROW_ON_ERROR);

        config()->set('services.arca.homologation.enabled', true);
        config()->set(
            'services.arca.homologation.wsaa_endpoint',
            'https://synthetic.invalid/wsaa'
        );
        config()->set(
            'services.arca.homologation.business_endpoint',
            'https://synthetic.invalid/wsfe'
        );
        config()->set('services.arca.homologation.service_name', 'wsfe');
        config()->set('services.arca.production.enabled', false);
    }

    protected function tearDown(): void
    {
        $key = EnvironmentWsaaCredentialMaterialReferenceStore::ENVIRONMENT_KEY;

        if ($this->referenceMapExisted) {
            $_SERVER[$key] = $this->previousReferenceMap;
        } else {
            unset($_SERVER[$key]);
        }

        parent::tearDown();
    }

    public function test_container_binds_complete_authorization_runtime_without_enabling_production(): void
    {
        $scope = app(FiscalAuthorizationRuntimeScopeStore::class);

        $this->assertInstanceOf(
            EnvironmentFiscalAuthorizationRuntimeScopeStore::class,
            $scope
        );
        $this->assertSame(
            $scope,
            app(FiscalAuthorizationCredentialStore::class)
        );
        $this->assertInstanceOf(
            WsaaBackedFiscalRemoteSequenceAuthority::class,
            app(FiscalRemoteSequenceAuthority::class)
        );
        $this->assertInstanceOf(
            WsaaBackedFiscalAuthorizationTransport::class,
            app(FiscalAuthorizationTransport::class)
        );
        $this->assertInstanceOf(
            WsfeFecaeRequestComposer::class,
            app(WsfeFecaeRequestComposerContract::class)
        );
        $this->assertInstanceOf(
            ArcaFiscalAuthorizationAdapter::class,
            app(ArcaFiscalAuthorizationAdapter::class)
        );
        $this->assertFalse(
            (bool) config('services.arca.production.enabled', false)
        );
    }

    public function test_scope_comes_from_explicit_wsaa_reference_map_not_fiscal_profile(): void
    {
        $store = new EnvironmentFiscalAuthorizationRuntimeScopeStore;

        $this->assertTrue($store->configuredFor(7));
        $this->assertFalse($store->configuredFor(8));

        $request = $store->accessTicketRequestFor(
            7,
            FiscalEnvironment::Homologation,
        );

        $this->assertSame(7, $request->organizationId);
        $this->assertSame('wsfe', $request->service);
        $this->assertSame('20123456789', $request->issuerCuit);
    }

    public function test_remote_sequence_runtime_gets_ticket_then_uses_wire_transport(): void
    {
        $tickets = $this->syntheticTicketProvider();
        $wire = new class implements WsfeCompUltimoAutorizadoSoapTransport {
            public int $calls = 0;

            public function exchange(
                WsfeCompUltimoAutorizadoSoap11Call $call
            ): FiscalRemoteSequenceState {
                $this->calls++;
                $parameters = $call->operationParameters();

                if (
                    $parameters['Auth']['Cuit'] !== '20123456789'
                    || $parameters['PtoVta'] !== 3
                    || $parameters['CbteTipo'] !== 1
                ) {
                    throw new DomainException('Synthetic remote sequence mismatch.');
                }

                $query = $call->query();

                return new FiscalRemoteSequenceState(
                    $query->environment,
                    $query->pointOfSaleNumber,
                    $query->voucherTypeCode,
                    41,
                );
            }
        };

        $authority = new WsaaBackedFiscalRemoteSequenceAuthority(
            $this->syntheticReadiness(),
            new EnvironmentFiscalAuthorizationRuntimeScopeStore,
            $tickets,
            $wire,
            $this->syntheticClock(),
        );

        $state = $authority->lastAuthorized(
            new FiscalRemoteSequenceQuery(
                organizationId: 7,
                environment: FiscalEnvironment::Homologation,
                pointOfSaleNumber: 3,
                voucherTypeCode: 1,
            )
        );

        $this->assertSame(41, $state->lastAuthorizedNumber);
        $this->assertSame(1, $tickets->calls);
        $this->assertSame(1, $wire->calls);
    }

    public function test_authorization_runtime_converges_synthetic_fecae_result(): void
    {
        $tickets = $this->syntheticTicketProvider();
        $wire = new class implements WsfeFecaeSoapTransport {
            public int $calls = 0;

            public function exchange(
                WsfeFecaeSoap11Call $call
            ): WsfeFecaeSoapResultData {
                $this->calls++;
                $parameters = $call->operationParameters();

                if (
                    $parameters['Auth']['Cuit'] !== '20123456789'
                    || $parameters['FeCAEReq']['FeCabReq']['PtoVta'] !== 3
                    || $parameters['FeCAEReq']['FeCabReq']['CbteTipo'] !== 1
                ) {
                    throw new DomainException('Synthetic FECAE mismatch.');
                }

                return new WsfeFecaeSoapResultData([
                    'FeCabResp' => [
                        'Resultado' => 'A',
                    ],
                    'FeDetResp' => [
                        'FECAEDetResponse' => [[
                            'Resultado' => 'A',
                            'CAE' => '71234567890123',
                            'CAEFchVto' => '20260930',
                        ]],
                    ],
                    'Events' => [],
                    'Errors' => [],
                ]);
            }
        };

        $transport = new WsaaBackedFiscalAuthorizationTransport(
            $this->syntheticReadiness(),
            new EnvironmentFiscalAuthorizationRuntimeScopeStore,
            $tickets,
            $wire,
            new WsfeFecaeProviderResponseNormalizer,
            new WsfeFecaeProviderResultConvergence,
            $this->syntheticClock(),
        );

        $result = $transport->authorize(
            $this->syntheticTransportRequest()
        );

        $this->assertSame(FiscalAuthorizationOutcome::Authorized, $result->outcome);
        $this->assertSame('71234567890123', $result->authorizationCode);
        $this->assertSame('2026-09-30', $result->authorizationCodeExpiresOn);
        $this->assertSame('arca_wsfe_v1', $result->providerEvidence['provider']);
        $this->assertSame(1, $tickets->calls);
        $this->assertSame(1, $wire->calls);
    }

    public function test_disabled_homologation_fails_before_ticket_or_wire_transport(): void
    {
        config()->set('services.arca.homologation.enabled', false);

        $tickets = $this->syntheticTicketProvider();
        $wire = new class implements WsfeCompUltimoAutorizadoSoapTransport {
            public int $calls = 0;

            public function exchange(
                WsfeCompUltimoAutorizadoSoap11Call $call
            ): FiscalRemoteSequenceState {
                $this->calls++;
                throw new DomainException('Wire transport must not run.');
            }
        };

        $authority = new WsaaBackedFiscalRemoteSequenceAuthority(
            $this->syntheticReadiness(),
            new EnvironmentFiscalAuthorizationRuntimeScopeStore,
            $tickets,
            $wire,
            $this->syntheticClock(),
        );

        try {
            $authority->lastAuthorized(
                new FiscalRemoteSequenceQuery(
                    organizationId: 7,
                    environment: FiscalEnvironment::Homologation,
                    pointOfSaleNumber: 3,
                    voucherTypeCode: 1,
                )
            );
            $this->fail('Disabled homologation must fail closed.');
        } catch (DomainException) {
            $this->assertSame(0, $tickets->calls);
            $this->assertSame(0, $wire->calls);
        }
    }

    private function syntheticReadiness(): ArcaHomologationReadiness
    {
        $references = new class implements WsaaCredentialMaterialReferenceStore {
            public function hasAny(FiscalEnvironment $environment): bool
            {
                return $environment === FiscalEnvironment::Homologation;
            }

            public function forRequest(
                WsaaAccessTicketRequest $request
            ): WsaaCredentialMaterialReference {
                return new WsaaCredentialMaterialReference(
                    organizationId: $request->organizationId,
                    environment: $request->environment,
                    service: $request->service,
                    issuerCuit: $request->issuerCuit,
                    certificateReference: 'file:synthetic/cert.pem',
                    privateKeyReference: 'file:synthetic/key.pem',
                    privateKeyPassphraseReference: null,
                );
            }
        };

        return new ArcaHomologationReadiness($references);
    }

    private function syntheticClock(): WsaaTraClock
    {
        return new class implements WsaaTraClock {
            public function now(): CarbonImmutable
            {
                return CarbonImmutable::parse('2026-08-19T21:00:00-03:00');
            }
        };
    }

    private function syntheticTicketProvider(): object
    {
        return new class implements WsaaAccessTicketProvider {
            public int $calls = 0;

            public function ticketFor(
                WsaaAccessTicketRequest $request
            ): WsaaAccessTicket {
                $this->calls++;

                return new WsaaAccessTicket(
                    organizationId: $request->organizationId,
                    environment: $request->environment,
                    service: $request->service,
                    issuerCuit: $request->issuerCuit,
                    token: 'synthetic-token',
                    sign: 'synthetic-sign',
                    generationTime: CarbonImmutable::parse('2026-08-19T20:55:00-03:00'),
                    expirationTime: CarbonImmutable::parse('2026-08-20T08:55:00-03:00'),
                );
            }
        };
    }

    private function syntheticTransportRequest(): FiscalAuthorizationTransportRequest
    {
        $fecae = new WsfeFecaeRequestData(
            new WsfeFecaeHeaderData(
                recordCount: 1,
                pointOfSaleNumber: 3,
                voucherTypeCode: 1,
            ),
            new WsfeFecaeDetailData(
                conceptCode: 1,
                documentTypeCode: 96,
                documentNumber: '12345678',
                voucherFrom: 42,
                voucherTo: 42,
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
                recipientVatConditionId: 5,
                vat: [[
                    'Id' => 5,
                    'BaseImp' => '100.00',
                    'Importe' => '21.00',
                ]],
            ),
        );

        return new FiscalAuthorizationTransportRequest(
            organizationId: 7,
            fiscalDocumentId: 99,
            environment: FiscalEnvironment::Homologation,
            pointOfSaleNumber: 3,
            voucherTypeCode: 1,
            voucherNumber: 42,
            fecaeRequest: $fecae,
        );
    }
}
