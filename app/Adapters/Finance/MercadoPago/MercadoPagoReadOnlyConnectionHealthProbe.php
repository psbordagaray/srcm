<?php

namespace App\Adapters\Finance\MercadoPago;

use App\Contracts\Finance\FinancialProviderConnectionHealthProbe;
use App\Contracts\Finance\MercadoPagoConnectionSecretStore;
use App\Domain\Finance\FinancialProviderHealthObservation;
use App\Enums\FinancialProviderCapability;
use App\Enums\FinancialProviderConnectionHealthStatus;
use App\Models\FinancialProviderConnection;
use DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class MercadoPagoReadOnlyConnectionHealthProbe implements
    FinancialProviderConnectionHealthProbe
{
    private const IDENTITY_URL =
        'https://api.mercadolibre.com/users/me';

    public function __construct(
        private readonly MercadoPagoConnectionSecretStore $secrets
    ) {
    }

    public function providerKey(): string
    {
        return 'mercado-pago';
    }

    public function capability(): FinancialProviderCapability
    {
        return FinancialProviderCapability::Read;
    }

    public function probe(
        FinancialProviderConnection $connection
    ): FinancialProviderHealthObservation {
        if ($connection->provider_key !== $this->providerKey()) {
            throw new DomainException(
                'El probe Mercado Pago no corresponde a esta conexión.'
            );
        }

        $startedAt = hrtime(true);

        try {
            $secrets = $this->secrets->forConnection($connection);
        } catch (DomainException) {
            return $this->observation(
                FinancialProviderConnectionHealthStatus::Unavailable,
                'credentials_unavailable',
                $startedAt
            );
        }

        try {
            $response = Http::withToken($secrets->accessToken)
                ->acceptJson()
                ->connectTimeout(2)
                ->timeout(5)
                ->get(self::IDENTITY_URL);
        } catch (ConnectionException) {
            return $this->observation(
                FinancialProviderConnectionHealthStatus::Unavailable,
                'transport_error',
                $startedAt
            );
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return $this->observation(
                FinancialProviderConnectionHealthStatus::Unavailable,
                'authentication_failed',
                $startedAt
            );
        }

        if ($response->status() === 429) {
            return $this->observation(
                FinancialProviderConnectionHealthStatus::Degraded,
                'rate_limited',
                $startedAt
            );
        }

        if ($response->serverError()) {
            return $this->observation(
                FinancialProviderConnectionHealthStatus::Unavailable,
                'provider_unavailable',
                $startedAt
            );
        }

        if (! $response->successful()) {
            return $this->observation(
                FinancialProviderConnectionHealthStatus::Degraded,
                'unexpected_http_status',
                $startedAt
            );
        }

        $providerUserId = $response->json('id');

        if (
            ! is_int($providerUserId)
            && ! is_string($providerUserId)
        ) {
            return $this->observation(
                FinancialProviderConnectionHealthStatus::Degraded,
                'invalid_provider_response',
                $startedAt
            );
        }

        $providerUserId = trim((string) $providerUserId);

        if (
            preg_match('/^[0-9]{1,30}$/D', $providerUserId) !== 1
        ) {
            return $this->observation(
                FinancialProviderConnectionHealthStatus::Degraded,
                'invalid_provider_response',
                $startedAt
            );
        }

        if ($providerUserId !== $secrets->userId) {
            return $this->observation(
                FinancialProviderConnectionHealthStatus::Unavailable,
                'identity_mismatch',
                $startedAt
            );
        }

        return $this->observation(
            FinancialProviderConnectionHealthStatus::Healthy,
            'ok',
            $startedAt
        );
    }

    private function observation(
        FinancialProviderConnectionHealthStatus $status,
        string $diagnosticCode,
        int $startedAt
    ): FinancialProviderHealthObservation {
        $elapsedNanoseconds = max(0, hrtime(true) - $startedAt);
        $latencyMs = min(
            600000,
            (int) ceil($elapsedNanoseconds / 1_000_000)
        );

        return new FinancialProviderHealthObservation(
            FinancialProviderCapability::Read,
            $status,
            now(),
            'mercado-pago:users-me',
            $diagnosticCode,
            $latencyMs
        );
    }
}
