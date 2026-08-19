<?php

namespace App\Domain\Fiscal;

use Carbon\CarbonImmutable;

interface WsaaTraClock
{
    public function now(): CarbonImmutable;
}
