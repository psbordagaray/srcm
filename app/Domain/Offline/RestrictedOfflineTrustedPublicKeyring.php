<?php

declare(strict_types=1);

namespace App\Domain\Offline;

use InvalidArgumentException;
use JsonException;

final readonly class RestrictedOfflineTrustedPublicKeyring
{
    public const MAX_KEYS = 16;

    /** @var array<string,array{kty:string,crv:string,x:string,alg:string,use:string}> */
    public array $keys;

    public string $fingerprint;

    /** @param array<string,array<string,mixed>> $keys */
    public function __construct(
        public int $version,
        array $keys,
    ) {
        if ($version < 1) {
            throw new InvalidArgumentException(
                'Trusted public keyring version must be positive.'
            );
        }

        if ($keys === [] || count($keys) > self::MAX_KEYS) {
            throw new InvalidArgumentException(
                'Trusted public keyring cardinality is invalid.'
            );
        }

        $normalized = [];

        foreach ($keys as $kid => $jwk) {
            if (! is_string($kid) || ! is_array($jwk)) {
                throw new InvalidArgumentException(
                    'Trusted public keyring entries are invalid.'
                );
            }

            RestrictedOfflineSignedGrantContract::assertKid($kid);
            $publicKey = RestrictedOfflineSignedGrantKeyCodec::publicKeyFromJwk(
                $jwk
            );
            $normalized[$kid] = RestrictedOfflineSignedGrantKeyCodec::publicKeyJwk(
                $publicKey
            );
        }

        ksort($normalized, SORT_STRING);
        $this->keys = $normalized;
        $this->fingerprint = hash(
            'sha256',
            json_encode(
                self::canonicalize([
                    'keyring_version' => $version,
                    'keys' => $normalized,
                ]),
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            )
        );
    }

    /** @return mixed */
    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }
}
