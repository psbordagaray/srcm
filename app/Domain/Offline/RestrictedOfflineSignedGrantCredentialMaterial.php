<?php

declare(strict_types=1);

namespace App\Domain\Offline;

use InvalidArgumentException;

final readonly class RestrictedOfflineSignedGrantCredentialMaterial
{
    /** @var array{alg:string,crv:string,kty:string,x:string,y:string} */
    public array $confirmationJwk;

    /** @param array<string,mixed> $confirmationJwk */
    public function __construct(
        public string $credentialId,
        public string $credentialFingerprint,
        public string $userHandle,
        array $confirmationJwk,
    ) {
        if (
            $credentialId === ''
            || strlen($credentialId) > 1024
            || preg_match('/^[A-Za-z0-9_-]+$/D', $credentialId) !== 1
        ) {
            throw new InvalidArgumentException(
                'Invalid verified WebAuthn credential id.'
            );
        }

        if (
            preg_match('/^[0-9a-f]{64}$/D', $credentialFingerprint) !== 1
            || strlen($userHandle) !== 32
        ) {
            throw new InvalidArgumentException(
                'Invalid verified WebAuthn credential material.'
            );
        }

        $expectedKeys = ['alg', 'crv', 'kty', 'x', 'y'];
        $actualKeys = array_keys($confirmationJwk);
        sort($actualKeys, SORT_STRING);

        if ($actualKeys !== $expectedKeys) {
            throw new InvalidArgumentException(
                'Invalid WebAuthn confirmation JWK shape.'
            );
        }

        if (
            $confirmationJwk['alg'] !== 'ES256'
            || $confirmationJwk['crv'] !== 'P-256'
            || $confirmationJwk['kty'] !== 'EC'
            || ! is_string($confirmationJwk['x'])
            || ! is_string($confirmationJwk['y'])
            || strlen(
                RestrictedOfflineSignedGrantKeyCodec::decodeBase64Url(
                    $confirmationJwk['x']
                )
            ) !== 32
            || strlen(
                RestrictedOfflineSignedGrantKeyCodec::decodeBase64Url(
                    $confirmationJwk['y']
                )
            ) !== 32
        ) {
            throw new InvalidArgumentException(
                'Verified WebAuthn credential must be ES256 P-256.'
            );
        }

        $this->confirmationJwk = [
            'alg' => 'ES256',
            'crv' => 'P-256',
            'kty' => 'EC',
            'x' => $confirmationJwk['x'],
            'y' => $confirmationJwk['y'],
        ];
    }
}
