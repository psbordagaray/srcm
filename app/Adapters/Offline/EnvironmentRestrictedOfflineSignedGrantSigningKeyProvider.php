<?php

declare(strict_types=1);

namespace App\Adapters\Offline;

use App\Contracts\Offline\RestrictedOfflineSignedGrantSigningKeyProvider;
use App\Domain\Offline\RestrictedOfflineSignedGrantContract;
use App\Domain\Offline\RestrictedOfflineSignedGrantKeyCodec;
use App\Domain\Offline\RestrictedOfflineSignedGrantSigningKey;
use App\Domain\Offline\RestrictedOfflineSignedGrantSigningKeyUnavailable;
use JsonException;
use Throwable;

final class EnvironmentRestrictedOfflineSignedGrantSigningKeyProvider implements RestrictedOfflineSignedGrantSigningKeyProvider
{
    public function current(): RestrictedOfflineSignedGrantSigningKey
    {
        if (config('offline.restricted_signed_grant.enabled') !== true) {
            throw new RestrictedOfflineSignedGrantSigningKeyUnavailable(
                'Restricted offline signed grant issuance is not provisioned.'
            );
        }

        $kid = config('offline.restricted_signed_grant.active_kid');
        $secretEnvironmentName = config(
            'offline.restricted_signed_grant.signing_secret_env'
        );
        $encodedSecret = is_string($secretEnvironmentName)
            ? $this->environmentValue($secretEnvironmentName)
            : null;
        $encodedKeyring = config(
            'offline.restricted_signed_grant.trusted_public_keyring_json'
        );

        if (! is_string($kid) || ! is_string($encodedSecret)) {
            throw $this->unavailable();
        }

        try {
            RestrictedOfflineSignedGrantContract::assertKid($kid);
            $secret = RestrictedOfflineSignedGrantKeyCodec::decodeBase64Url(
                $encodedSecret
            );

            if (strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
                throw $this->unavailable();
            }

            $keyring = $this->decodeKeyring($encodedKeyring);
            if (! array_key_exists($kid, $keyring)) {
                throw $this->unavailable();
            }

            $trustedPublicKey = null;
            foreach ($keyring as $entryKid => $jwk) {
                if (! is_string($entryKid) || ! is_array($jwk)) {
                    throw $this->unavailable();
                }

                RestrictedOfflineSignedGrantContract::assertKid($entryKid);
                $publicKey = RestrictedOfflineSignedGrantKeyCodec::publicKeyFromJwk(
                    $jwk
                );

                if ($entryKid === $kid) {
                    $trustedPublicKey = $publicKey;
                }
            }

            $derivedPublicKey = sodium_crypto_sign_publickey_from_secretkey(
                $secret
            );

            if (
                ! is_string($trustedPublicKey)
                || ! hash_equals($trustedPublicKey, $derivedPublicKey)
            ) {
                throw $this->unavailable();
            }

            return new RestrictedOfflineSignedGrantSigningKey(
                $kid,
                $secret
            );
        } catch (RestrictedOfflineSignedGrantSigningKeyUnavailable $exception) {
            throw $exception;
        } catch (Throwable) {
            throw $this->unavailable();
        }
    }


    private function environmentValue(string $name): ?string
    {
        if (preg_match('/^[A-Z][A-Z0-9_]{1,127}$/D', $name) !== 1) {
            return null;
        }

        $candidates = [
            $_ENV[$name] ?? null,
            $_SERVER[$name] ?? null,
            getenv($name),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    /** @return array<string,array<string,mixed>> */
    private function decodeKeyring(mixed $value): array
    {
        if (is_array($value)) {
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

    private function unavailable(): RestrictedOfflineSignedGrantSigningKeyUnavailable
    {
        return new RestrictedOfflineSignedGrantSigningKeyUnavailable(
            'Restricted offline signed grant signing material is unavailable.'
        );
    }
}
