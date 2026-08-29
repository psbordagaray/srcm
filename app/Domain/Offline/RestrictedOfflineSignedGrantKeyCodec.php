<?php

declare(strict_types=1);

namespace App\Domain\Offline;

use InvalidArgumentException;
use Lcobucci\JWT\Signer\Key\InMemory;

final class RestrictedOfflineSignedGrantKeyCodec
{
    public static function encodeBase64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function decodeBase64Url(string $value): string
    {
        if (
            $value === ''
            || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1
        ) {
            throw new InvalidArgumentException('Invalid base64url value.');
        }

        $padding = (4 - (strlen($value) % 4)) % 4;
        $decoded = base64_decode(
            strtr($value, '-_', '+/').str_repeat('=', $padding),
            true
        );

        if ($decoded === false || self::encodeBase64Url($decoded) !== $value) {
            throw new InvalidArgumentException('Non-canonical base64url value.');
        }

        return $decoded;
    }

    public static function signingKey(string $secretKey): InMemory
    {
        if (strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new InvalidArgumentException(
                'Ed25519 signing key must be a raw sodium secret key.'
            );
        }

        return InMemory::plainText($secretKey);
    }

    public static function verificationKey(string $publicKey): InMemory
    {
        if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new InvalidArgumentException(
                'Ed25519 verification key must be 32 raw bytes.'
            );
        }

        return InMemory::plainText($publicKey);
    }

    /** @return array{kty:string,crv:string,x:string,alg:string,use:string} */
    public static function publicKeyJwk(string $publicKey): array
    {
        self::verificationKey($publicKey);

        return [
            'kty' => 'OKP',
            'crv' => 'Ed25519',
            'x' => self::encodeBase64Url($publicKey),
            'alg' => RestrictedOfflineSignedGrantContract::ALGORITHM,
            'use' => 'sig',
        ];
    }

    /** @param array<string,mixed> $jwk */
    public static function publicKeyFromJwk(array $jwk): string
    {
        $keys = array_keys($jwk);
        sort($keys, SORT_STRING);

        if ($keys !== ['alg', 'crv', 'kty', 'use', 'x']) {
            throw new InvalidArgumentException(
                'Ed25519 public JWK must use the exact trusted shape.'
            );
        }

        if (
            $jwk['kty'] !== 'OKP'
            || $jwk['crv'] !== 'Ed25519'
            || $jwk['alg'] !== RestrictedOfflineSignedGrantContract::ALGORITHM
            || $jwk['use'] !== 'sig'
            || ! is_string($jwk['x'])
        ) {
            throw new InvalidArgumentException('Invalid Ed25519 public JWK.');
        }

        $publicKey = self::decodeBase64Url($jwk['x']);
        self::verificationKey($publicKey);

        return $publicKey;
    }
}
