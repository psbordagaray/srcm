<?php

namespace App\Domain\Fiscal;

final readonly class WsaaLoginTicketRequestBuilder implements
    WsaaTraBuilder
{
    public function __construct(
        private WsaaTraClock $clock,
        private WsaaTraUniqueIdProvider $uniqueIds,
        private WsaaTraWindowPolicy $window,
    ) {
    }

    public function build(
        WsaaAccessTicketRequest $request
    ): WsaaTra {
        $now =
            $this->clock
                ->now()
                ->utc();

        return new WsaaTra(
            uniqueId:
                $this->uniqueIds->next(),
            generationTime:
                $this->window
                    ->generationTime(
                        $now
                    ),
            expirationTime:
                $this->window
                    ->expirationTime(
                        $now
                    ),
            service:
                $request->service,
        );
    }
}
