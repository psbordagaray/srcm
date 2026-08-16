<?php

namespace App\Contracts\Finance;

use App\Domain\Finance\ExternalFinancialProviderObservation;
use App\Domain\Finance\FinancialProviderRefundRequest;
use App\Models\FinancialProviderConnection;

interface FinancialProviderRefundAdapter
{
    public function providerKey(): string;

    /**
     * Submit one refund operation using provider-side idempotency.
     *
     * Implementations MUST use $request->providerIdempotencyKey as the
     * provider idempotency key or an equivalent provider-supported mechanism.
     * A retry with the same key MUST NOT create a second refund.
     *
     * Raw provider payloads, tokens and secrets must remain transient.
     */
    public function submitRefund(
        FinancialProviderConnection $connection,
        FinancialProviderRefundRequest $request
    ): ExternalFinancialProviderObservation;
}
