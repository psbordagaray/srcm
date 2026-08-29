<?php

declare(strict_types=1);

namespace App\Domain\Offline;

use InvalidArgumentException;

final class RestrictedOfflineSignedGrantContract
{
    public const VERSION = 1;
    public const TYPE = 'SRCM-OFFLINE-GRANT';
    public const ALGORITHM = 'EdDSA';
    public const ISSUER = 'urn:srcm:server';
    public const AUDIENCE = 'urn:srcm:restricted-offline';
    public const DEFAULT_TTL_SECONDS = 14400;
    public const HARD_MAX_TTL_SECONDS = 28800;
    public const CLOCK_SKEW_SECONDS = 120;
    public const PASSKEY_CONFIRMATION_MAX_AGE_SECONDS = 300;
    public const MAX_COMPACT_BYTES = 4096;

    public const CAPABILITIES = [
        'restricted_offline_read_model',
        'restricted_offline_replay',
    ];

    public const CLAIMS = [
        'iss',
        'aud',
        'sub',
        'jti',
        'iat',
        'nbf',
        'exp',
        'srcm_ver',
        'membership_id',
        'organization_id',
        'device_public_id',
        'binding_public_id',
        'binding_exp',
        'capabilities',
        'policy_version',
        'credential_id',
        'credential_fingerprint',
        'cnf',
    ];

    public static function assertKid(string $kid): void
    {
        if (
            preg_match('/^[A-Za-z][A-Za-z0-9._-]{0,63}$/D', $kid) !== 1
        ) {
            throw new InvalidArgumentException('Invalid offline grant kid.');
        }
    }

    /** @param list<string> $capabilities
     *  @return list<string>
     */
    public static function normalizeCapabilities(array $capabilities): array
    {
        if ($capabilities === []) {
            throw new InvalidArgumentException(
                'At least one restricted offline capability is required.'
            );
        }

        $normalized = [];

        foreach ($capabilities as $capability) {
            if (
                ! is_string($capability)
                || ! in_array($capability, self::CAPABILITIES, true)
            ) {
                throw new InvalidArgumentException(
                    'Unsupported restricted offline capability.'
                );
            }

            $normalized[$capability] = true;
        }

        $values = array_keys($normalized);
        sort($values, SORT_STRING);

        return $values;
    }

    public static function assertCompact(string $compact): void
    {
        $length = strlen($compact);

        if ($length < 1 || $length > self::MAX_COMPACT_BYTES) {
            throw new InvalidArgumentException(
                'Restricted offline grant compact size is invalid.'
            );
        }
    }
}
