<?php

namespace App\Domain\Fiscal;

interface WsaaTraUniqueIdProvider
{
    public function next(): int;
}
