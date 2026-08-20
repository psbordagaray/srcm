<?php

namespace App\Adapters\Fiscal\Arca;

use App\Domain\Fiscal\ArcaHomologationReadiness;
use App\Domain\Fiscal\FiscalAuthorizationRuntimeScopeStore;
use App\Domain\Fiscal\FiscalAuthorizationTransport;
use App\Domain\Fiscal\FiscalAuthorizationTransportRequest;
use App\Domain\Fiscal\FiscalAuthorizationTransportResult;
use App\Domain\Fiscal\WsaaAccessTicketProvider;
use App\Domain\Fiscal\WsaaTraClock;
use App\Domain\Fiscal\WsfeFecaeProviderResponseNormalizerContract;
use App\Domain\Fiscal\WsfeFecaeProviderResultConvergenceContract;
use App\Domain\Fiscal\WsfeFecaeSoap11Call;
use App\Domain\Fiscal\WsfeFecaeSoapTransport;

final class WsaaBackedFiscalAuthorizationTransport implements
    FiscalAuthorizationTransport
{
    public function __construct(
        private readonly ArcaHomologationReadiness $readiness,
        private readonly FiscalAuthorizationRuntimeScopeStore $scopes,
        private readonly WsaaAccessTicketProvider $tickets,
        private readonly WsfeFecaeSoapTransport $transport,
        private readonly WsfeFecaeProviderResponseNormalizerContract $normalizer,
        private readonly WsfeFecaeProviderResultConvergenceContract $convergence,
        private readonly WsaaTraClock $clock,
    ) {
    }

    public function authorize(
        FiscalAuthorizationTransportRequest $request
    ): FiscalAuthorizationTransportResult {
        $this->readiness->assertReady();

        $scope = $this->scopes->accessTicketRequestFor(
            $request->organizationId,
            $request->environment,
        );

        $ticket = $this->tickets->ticketFor($scope);

        $providerResult = $this->transport->exchange(
            new WsfeFecaeSoap11Call(
                $request,
                $scope,
                $ticket,
                $this->clock->now(),
            )
        );

        return $this->convergence->converge(
            $this->normalizer->normalize(
                $providerResult
            )
        );
    }
}
