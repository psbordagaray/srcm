<?php

namespace Tests\Feature\Fiscal;

use App\Domain\Fiscal\FiscalAuthorizationTransportRequest;
use App\Domain\Fiscal\WsaaAccessTicket;
use App\Domain\Fiscal\WsaaAccessTicketProvider;
use App\Domain\Fiscal\WsaaAccessTicketRequest;
use App\Enums\FiscalEnvironment;
use Carbon\CarbonImmutable;
use DomainException;
use ReflectionClass;
use Tests\TestCase;

class WsaaAccessTicketBoundaryTest extends TestCase
{
    public function test_request_scope_is_explicit_and_fail_closed(): void
    {
        $request = new WsaaAccessTicketRequest(
            91,
            FiscalEnvironment::Homologation,
            'wsfe_explicit',
            '20123456786'
        );

        $this->assertSame(
            91,
            $request->organizationId
        );
        $this->assertSame(
            FiscalEnvironment::Homologation,
            $request->environment
        );
        $this->assertSame(
            'wsfe_explicit',
            $request->service
        );
        $this->assertSame(
            '20123456786',
            $request->issuerCuit
        );

        foreach (
            [
                [0, 'wsfe_explicit', '20123456786'],
                [91, '', '20123456786'],
                [91, ' wsfe', '20123456786'],
                [91, 'wsfe', '20-12345678-6'],
            ]
            as [$organizationId, $service, $cuit]
        ) {
            try {
                new WsaaAccessTicketRequest(
                    $organizationId,
                    FiscalEnvironment::Homologation,
                    $service,
                    $cuit
                );

                $this->fail(
                    'El scope WSAA inválido debía fallar.'
                );
            } catch (DomainException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_ticket_is_usable_only_for_exact_scope_and_live_window(): void
    {
        $request = new WsaaAccessTicketRequest(
            91,
            FiscalEnvironment::Homologation,
            'wsfe_explicit',
            '20123456786'
        );

        $ticket = $this->ticket();

        $ticket->assertUsableFor(
            $request,
            CarbonImmutable::parse(
                '2026-08-18T23:30:00-03:00'
            )
        );

        $this->assertSame(
            'secret-token',
            $ticket->token()
        );
        $this->assertSame(
            'secret-sign',
            $ticket->sign()
        );

        foreach (
            [
                new WsaaAccessTicketRequest(
                    92,
                    FiscalEnvironment::Homologation,
                    'wsfe_explicit',
                    '20123456786'
                ),
                new WsaaAccessTicketRequest(
                    91,
                    FiscalEnvironment::Production,
                    'wsfe_explicit',
                    '20123456786'
                ),
                new WsaaAccessTicketRequest(
                    91,
                    FiscalEnvironment::Homologation,
                    'other_service',
                    '20123456786'
                ),
                new WsaaAccessTicketRequest(
                    91,
                    FiscalEnvironment::Homologation,
                    'wsfe_explicit',
                    '27111111111'
                ),
            ]
            as $wrongScope
        ) {
            try {
                $ticket->assertUsableFor(
                    $wrongScope,
                    CarbonImmutable::parse(
                        '2026-08-18T23:30:00-03:00'
                    )
                );

                $this->fail(
                    'El TA de otro scope debía fallar.'
                );
            } catch (DomainException) {
                $this->assertTrue(true);
            }
        }

        foreach (
            [
                '2026-08-18T21:59:59-03:00',
                '2026-08-19T10:00:00-03:00',
            ]
            as $invalidInstant
        ) {
            try {
                $ticket->assertUsableFor(
                    $request,
                    CarbonImmutable::parse(
                        $invalidInstant
                    )
                );

                $this->fail(
                    'El TA fuera de vigencia debía fallar.'
                );
            } catch (DomainException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_ticket_secret_material_is_not_public_json_or_debug_output(): void
    {
        $ticket = $this->ticket();

        $properties = array_map(
            static fn (
                \ReflectionProperty $property
            ): string => $property->getName(),
            (
                new ReflectionClass(
                    WsaaAccessTicket::class
                )
            )->getProperties(
                \ReflectionProperty::IS_PUBLIC
            )
        );

        $this->assertNotContains(
            'token',
            $properties
        );
        $this->assertNotContains(
            'sign',
            $properties
        );

        $json = json_encode(
            $ticket,
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
        var_dump($ticket);
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
    }

    public function test_ticket_refuses_php_serialization(): void
    {
        $this->expectException(
            DomainException::class
        );

        serialize($this->ticket());
    }

    public function test_provider_is_explicit_contract_and_transport_request_stays_secret_free(): void
    {
        $this->assertTrue(
            (
                new ReflectionClass(
                    WsaaAccessTicketProvider::class
                )
            )->isInterface()
        );

        $provider = new RecordingWsaaAccessTicketProvider(
            $this->ticket()
        );

        $request = new WsaaAccessTicketRequest(
            91,
            FiscalEnvironment::Homologation,
            'wsfe_explicit',
            '20123456786'
        );

        $provided = $provider->ticketFor(
            $request
        );

        $this->assertSame(
            $request,
            $provider->request
        );
        $this->assertSame(
            'secret-token',
            $provided->token()
        );

        $transportProperties = array_map(
            static fn (
                \ReflectionProperty $property
            ): string => $property->getName(),
            (
                new ReflectionClass(
                    FiscalAuthorizationTransportRequest::class
                )
            )->getProperties()
        );

        foreach (
            [
                'token',
                'sign',
                'issuerCuit',
                'certificate',
                'privateKey',
                'accessTicket',
            ]
            as $forbidden
        ) {
            $this->assertNotContains(
                $forbidden,
                $transportProperties
            );
        }
    }

    private function ticket(): WsaaAccessTicket
    {
        return new WsaaAccessTicket(
            91,
            FiscalEnvironment::Homologation,
            'wsfe_explicit',
            '20123456786',
            'secret-token',
            'secret-sign',
            CarbonImmutable::parse(
                '2026-08-18T22:00:00-03:00'
            ),
            CarbonImmutable::parse(
                '2026-08-19T10:00:00-03:00'
            )
        );
    }
}

final class RecordingWsaaAccessTicketProvider implements WsaaAccessTicketProvider
{
    public ?WsaaAccessTicketRequest $request = null;

    public function __construct(
        private readonly WsaaAccessTicket $ticket
    ) {
    }

    public function ticketFor(
        WsaaAccessTicketRequest $request
    ): WsaaAccessTicket {
        $this->request = $request;

        return $this->ticket;
    }
}
