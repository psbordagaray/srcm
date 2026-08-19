<?php

namespace App\Domain\Fiscal;

interface FiscalRemoteSequenceAuthority
{
    public function lastAuthorized(
        FiscalRemoteSequenceQuery $query
    ): FiscalRemoteSequenceState;
}
