<?php

namespace App\Adapters\Finance\MercadoPago;

use App\Contracts\Finance\MercadoPagoConnectionSecretStore;
use App\Models\FinancialProviderConnection;
use DomainException;
use JsonException;

final class EnvironmentMercadoPagoConnectionSecretStore implements
    MercadoPagoConnectionSecretStore
{
    public const ENVIRONMENT_KEY =
        'SRCM_MERCADO_PAGO_CONNECTION_SECRETS_JSON';

    public function forConnection(
        FinancialProviderConnection $connection
    ): MercadoPagoConnectionSecrets {
        if (
            $connection->provider_key !== 'mercado-pago'
            || ! $connection->active
        ) {
            throw new DomainException(
                'La conexión Mercado Pago no está disponible.'
            );
        }

        $raw = $this->environmentValue();

        if (
            $raw === null
            || trim($raw) === ''
            || strlen($raw) > 65535
        ) {
            throw new DomainException(
                'La configuración segura de Mercado Pago no está disponible.'
            );
        }

        try {
            $decoded = json_decode(
                $raw,
                true,
                8,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            throw new DomainException(
                'La configuración segura de Mercado Pago no es válida.'
            );
        }

        if (! is_array($decoded)) {
            throw new DomainException(
                'La configuración segura de Mercado Pago no es válida.'
            );
        }

        $connectionKey = strtolower(
            trim((string) $connection->public_id)
        );

        $entry = $decoded[$connectionKey] ?? null;

        if (! is_array($entry)) {
            throw new DomainException(
                'La conexión Mercado Pago no posee secretos configurados.'
            );
        }

        $allowedKeys = [
            'webhook_secret',
            'access_token',
            'application_id',
            'user_id',
            'live_mode',
        ];

        $keys = array_keys($entry);
        sort($keys);
        $expectedKeys = $allowedKeys;
        sort($expectedKeys);

        if ($keys !== $expectedKeys) {
            throw new DomainException(
                'La configuración segura de Mercado Pago es incompleta.'
            );
        }

        $webhookSecret = $this->secret(
            $entry['webhook_secret'] ?? null
        );
        $accessToken = $this->secret(
            $entry['access_token'] ?? null
        );
        $applicationId = $this->numericIdentifier(
            $entry['application_id'] ?? null
        );
        $userId = $this->numericIdentifier(
            $entry['user_id'] ?? null
        );

        if (! is_bool($entry['live_mode'] ?? null)) {
            throw new DomainException(
                'La configuración segura de Mercado Pago posee un modo inválido.'
            );
        }

        if (
            filled($connection->external_account_id)
            && trim((string) $connection->external_account_id)
                !== $userId
        ) {
            throw new DomainException(
                'La identidad externa de la conexión Mercado Pago no coincide.'
            );
        }

        return new MercadoPagoConnectionSecrets(
            webhookSecret: $webhookSecret,
            accessToken: $accessToken,
            applicationId: $applicationId,
            userId: $userId,
            liveMode: $entry['live_mode']
        );
    }

    private function environmentValue(): ?string
    {
        foreach (
            [
                $_SERVER[self::ENVIRONMENT_KEY] ?? null,
                $_ENV[self::ENVIRONMENT_KEY] ?? null,
                getenv(self::ENVIRONMENT_KEY),
            ] as $value
        ) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function secret(mixed $value): string
    {
        if (! is_string($value)) {
            throw new DomainException(
                'La configuración segura de Mercado Pago posee un secreto inválido.'
            );
        }

        $value = trim($value);

        if ($value === '' || strlen($value) > 2048) {
            throw new DomainException(
                'La configuración segura de Mercado Pago posee un secreto inválido.'
            );
        }

        return $value;
    }

    private function numericIdentifier(mixed $value): string
    {
        if (! is_string($value) && ! is_int($value)) {
            throw new DomainException(
                'La configuración segura de Mercado Pago posee una identidad inválida.'
            );
        }

        $value = trim((string) $value);

        if (preg_match('/^[0-9]{1,30}$/D', $value) !== 1) {
            throw new DomainException(
                'La configuración segura de Mercado Pago posee una identidad inválida.'
            );
        }

        return $value;
    }
}
