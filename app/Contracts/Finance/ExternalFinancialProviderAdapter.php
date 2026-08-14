<?php

namespace App\Contracts\Finance;

use App\Domain\Finance\ExternalFinancialProviderObservation;

interface ExternalFinancialProviderAdapter
{
    public function providerKey(): string;

    /**
     * Normalize provider payload into safe financial evidence.
     *
     * The raw payload is transient input. An adapter must never persist or
     * forward PAN, CVV, access tokens, secrets or other sensitive material.
     *
     * @param array<string, mixed> $payload
     */
    public function normalize(array $payload): ExternalFinancialProviderObservation;
}
