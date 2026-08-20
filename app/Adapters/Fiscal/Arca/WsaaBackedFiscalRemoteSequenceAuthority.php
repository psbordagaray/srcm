<?php

namespace App\Adapters\Fiscal\Arca;

use App\Domain\Fiscal\ArcaHomologationReadiness;
use App\Domain\Fiscal\FiscalAuthorizationRuntimeScopeStore;
use App\Domain\Fiscal\FiscalRemoteSequenceAuthority;
use App\Domain\Fiscal\FiscalRemoteSequenceQuery;
use App\Domain\Fiscal\FiscalRemoteSequenceState;
use App\Domain\Fiscal\WsaaAccessTicketProvider;
use App\Domain\Fiscal\WsaaTraClock;
use App\Domain\Fiscal\WsfeCompUltimoAutorizadoSoap11Call;
use App\Domain\Fiscal\WsfeCompUltimoAutorizadoSoapTransport;

final class WsaaBackedFiscalRemoteSequenceAuthority implements
    FiscalRemoteSequenceAuthority
{
    public function __construct(
        private readonly ArcaHomologationReadiness $readiness,
        private readonly FiscalAuthorizationRuntimeScopeStore $scopes,
        private readonly WsaaAccessTicketProvider $tickets,
        private readonly WsfeCompUltimoAutorizadoSoapTransport $transport,
        private readonly WsaaTraClock $clock,
    ) {
    }

    public function lastAuthorized(
        FiscalRemoteSequenceQuery $query
    ): FiscalRemoteSequenceState {
        $this->readiness->assertReady();

        $scope = $this->scopes->accessTicketRequestFor(
            $query->organizationId,
            $query->environment,
        );

        $ticket = $this->tickets->ticketFor($scope);

        return $this->transport->exchange(
            new WsfeCompUltimoAutorizadoSoap11Call(
                $query,
                $scope,
                $ticket,
                $this->clock->now(),
            )
        );
    }
}
