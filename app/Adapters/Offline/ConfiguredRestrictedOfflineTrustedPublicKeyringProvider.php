<?php

declare(strict_types=1);

namespace App\Adapters\Offline;

use App\Contracts\Offline\RestrictedOfflineTrustedPublicKeyringProvider;
use App\Domain\Offline\RestrictedOfflineTrustedPublicKeyring;
use App\Domain\Offline\RestrictedOfflineTrustedPublicKeyringUnavailable;
use JsonException;
use Throwable;

final class ConfiguredRestrictedOfflineTrustedPublicKeyringProvider implements
    RestrictedOfflineTrustedPublicKeyringProvider
{
    public function current(): RestrictedOfflineTrustedPublicKeyring
    {
        try {
            $version = $this->version(
                config(
                    'offline.restricted_signed_grant.trusted_public_keyring_version'
                )
            );
            $keys = $this->keys(
                config(
                    'offline.restricted_signed_grant.trusted_public_keyring_json'
                )
            );

            return new RestrictedOfflineTrustedPublicKeyring(
                $version,
                $keys
            );
        } catch (RestrictedOfflineTrustedPublicKeyringUnavailable $exception) {
            throw $exception;
        } catch (Throwable) {
            throw $this->unavailable();
        }
    }

    private function version(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (
            is_string($value)
            && preg_match('/^[1-9][0-9]{0,17}$/D', $value) === 1
        ) {
            $parsed = filter_var($value, FILTER_VALIDATE_INT);
            if (is_int($parsed) && $parsed > 0) {
                return $parsed;
            }
        }

        throw $this->unavailable();
    }

    /** @return array<string,array<string,mixed>> */
    private function keys(mixed $value): array
    {
        if (is_array($value) && ! array_is_list($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            throw $this->unavailable();
        }

        try {
            $decoded = json_decode(
                $value,
                true,
                32,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            throw $this->unavailable();
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw $this->unavailable();
        }

        return $decoded;
    }

    private function unavailable(): RestrictedOfflineTrustedPublicKeyringUnavailable
    {
        return new RestrictedOfflineTrustedPublicKeyringUnavailable(
            'Restricted offline trusted public keyring is unavailable.'
        );
    }
}
