<?php

namespace App\Adapters\Fiscal\Arca;

use App\Domain\Fiscal\WsaaTraClock;
use Carbon\CarbonImmutable;

final class SystemWsaaTraClock implements WsaaTraClock
{
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }
}
