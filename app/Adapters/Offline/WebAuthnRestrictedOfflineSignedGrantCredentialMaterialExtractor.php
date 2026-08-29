<?php

declare(strict_types=1);

namespace App\Adapters\Offline;

use App\Contracts\Offline\RestrictedOfflineSignedGrantCredentialMaterialExtractor;
use App\Domain\Offline\RestrictedOfflineSignedGrantCredentialMaterial;
use App\Domain\Offline\RestrictedOfflineSignedGrantKeyCodec;
use CBOR\Decoder;
use CBOR\Normalizable;
use InvalidArgumentException;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Support\WebAuthn;
use Throwable;
use Webauthn\CredentialRecord;
use Webauthn\StringStream;

final class WebAuthnRestrictedOfflineSignedGrantCredentialMaterialExtractor implements RestrictedOfflineSignedGrantCredentialMaterialExtractor
{
    public function extract(
        Passkey $passkey
    ): RestrictedOfflineSignedGrantCredentialMaterial {
        try {
            $record = WebAuthn::fromJson(
                json_encode(
                    $passkey->credential,
                    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                ),
                CredentialRecord::class
            );

            $storedCredentialId = RestrictedOfflineSignedGrantKeyCodec::decodeBase64Url(
                (string) $passkey->credential_id
            );

            if (! hash_equals($record->publicKeyCredentialId, $storedCredentialId)) {
                throw new InvalidArgumentException(
                    'Stored WebAuthn credential id does not match its credential record.'
                );
            }

            return $this->fromRawCose(
                (string) $passkey->credential_id,
                $record->credentialPublicKey,
                $record->userHandle
            );
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new InvalidArgumentException(
                'Unable to extract verified WebAuthn credential material.',
                0,
                $exception
            );
        }
    }

    public function fromRawCose(
        string $credentialId,
        string $credentialPublicKey,
        string $userHandle
    ): RestrictedOfflineSignedGrantCredentialMaterial {
        try {
            $stream = new StringStream($credentialPublicKey);
            $decoded = Decoder::create()->decode($stream);

            if (! $stream->isEOF()) {
                throw new InvalidArgumentException(
                    'WebAuthn COSE key has trailing bytes.'
                );
            }
            $stream->close();

            if (! $decoded instanceof Normalizable) {
                throw new InvalidArgumentException(
                    'WebAuthn COSE key is not normalizable.'
                );
            }

            $map = $decoded->normalize();
            if (! is_array($map)) {
                throw new InvalidArgumentException(
                    'WebAuthn COSE key must be a map.'
                );
            }

            $keys = array_keys($map);
            foreach ($keys as $key) {
                if (! is_int($key)) {
                    throw new InvalidArgumentException(
                        'WebAuthn COSE key labels must be integers.'
                    );
                }
            }
            sort($keys, SORT_NUMERIC);
            $requiredLabels = [-3, -2, -1, 1, 3];
            $allowedLabels = [-3, -2, -1, 1, 2, 3, 4, 5];

            foreach ($requiredLabels as $requiredLabel) {
                if (! array_key_exists($requiredLabel, $map)) {
                    throw new InvalidArgumentException(
                        'WebAuthn COSE key is missing a required ES256 EC2 label.'
                    );
                }
            }
            foreach ($keys as $key) {
                if (! in_array($key, $allowedLabels, true)) {
                    throw new InvalidArgumentException(
                        'WebAuthn COSE key contains an unsupported label.'
                    );
                }
            }

            if (
                (string) $map[1] !== '2'
                || (string) $map[3] !== '-7'
                || (string) $map[-1] !== '1'
                || ! is_string($map[-2])
                || ! is_string($map[-3])
                || strlen($map[-2]) !== 32
                || strlen($map[-3]) !== 32
            ) {
                throw new InvalidArgumentException(
                    'WebAuthn credential must be ES256 on P-256.'
                );
            }

            return new RestrictedOfflineSignedGrantCredentialMaterial(
                credentialId: $credentialId,
                credentialFingerprint: hash('sha256', $credentialPublicKey),
                userHandle: $userHandle,
                confirmationJwk: [
                    'alg' => 'ES256',
                    'crv' => 'P-256',
                    'kty' => 'EC',
                    'x' => RestrictedOfflineSignedGrantKeyCodec::encodeBase64Url(
                        $map[-2]
                    ),
                    'y' => RestrictedOfflineSignedGrantKeyCodec::encodeBase64Url(
                        $map[-3]
                    ),
                ],
            );
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new InvalidArgumentException(
                'Unable to decode WebAuthn COSE credential public key.',
                0,
                $exception
            );
        }
    }
}
