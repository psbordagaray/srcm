<?php

namespace App\Adapters\Fiscal\Arca;

use App\Domain\Fiscal\WsaaTra;
use App\Domain\Fiscal\WsaaTraUniqueIdProvider;

final class RandomWsaaTraUniqueIdProvider implements WsaaTraUniqueIdProvider
{
    public function next(): int
    {
        return random_int(0, WsaaTra::MAX_UNIQUE_ID);
    }
}
