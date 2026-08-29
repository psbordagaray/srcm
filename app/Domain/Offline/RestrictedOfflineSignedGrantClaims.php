<?php

declare(strict_types=1);

namespace App\Domain\Offline;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class RestrictedOfflineSignedGrantClaims
{
    /** @var list<string> */
    public array $capabilities;

    /** @var array{alg:string,crv:string,kty:string,x:string,y:string} */
    public array $confirmationJwk;

    /**
     * @param list<string> $capabilities
     * @param array<string,mixed> $confirmationJwk
     */
    public function __construct(
        public string $subject,
        public string $jti,
        public DateTimeImmutable $issuedAt,
        public DateTimeImmutable $expiresAt,
        public int $membershipId,
        public int $organizationId,
        public string $devicePublicId,
        public string $bindingPublicId,
        public DateTimeImmutable $bindingExpiresAt,
        array $capabilities,
        public string $policyVersion,
        public string $credentialId,
        public string $credentialFingerprint,
        array $confirmationJwk,
    ) {
        $this->assertSubject($subject);
        $this->assertUuidV4($jti, 'jti');
        $this->assertUuid($devicePublicId, 'device public id');
        $this->assertUuid($bindingPublicId, 'binding public id');

        if ($membershipId < 1 || $organizationId < 1) {
            throw new InvalidArgumentException(
                'Membership and organization identifiers must be positive.'
            );
        }

        foreach ([$issuedAt, $expiresAt, $bindingExpiresAt] as $instant) {
            if ($instant->format('u') !== '000000') {
                throw new InvalidArgumentException(
                    'Restricted offline grant times must use whole-second precision.'
                );
            }
        }

        $ttl = $expiresAt->getTimestamp() - $issuedAt->getTimestamp();

        if (
            $ttl < 1
            || $ttl > RestrictedOfflineSignedGrantContract::HARD_MAX_TTL_SECONDS
        ) {
            throw new InvalidArgumentException(
                'Restricted offline grant TTL is outside the contract.'
            );
        }

        if ($expiresAt > $bindingExpiresAt) {
            throw new InvalidArgumentException(
                'Restricted offline grant cannot outlive browser binding.'
            );
        }

        $this->capabilities =
            RestrictedOfflineSignedGrantContract::normalizeCapabilities(
                $capabilities
            );

        if (
            preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/D', $policyVersion)
            !== 1
        ) {
            throw new InvalidArgumentException('Invalid offline policy version.');
        }

        if (
            $credentialId === ''
            || strlen($credentialId) > 1024
            || preg_match('/^[A-Za-z0-9_-]+$/D', $credentialId) !== 1
        ) {
            throw new InvalidArgumentException('Invalid WebAuthn credential id.');
        }

        if (
            preg_match('/^[0-9a-f]{64}$/D', $credentialFingerprint) !== 1
        ) {
            throw new InvalidArgumentException(
                'Invalid WebAuthn credential fingerprint.'
            );
        }

        $this->confirmationJwk = self::normalizeConfirmationJwk(
            $confirmationJwk
        );
    }

    /** @return array<string,mixed> */
    public function privateClaims(): array
    {
        return [
            'srcm_ver' => RestrictedOfflineSignedGrantContract::VERSION,
            'membership_id' => $this->membershipId,
            'organization_id' => $this->organizationId,
            'device_public_id' => $this->devicePublicId,
            'binding_public_id' => $this->bindingPublicId,
            'binding_exp' => $this->bindingExpiresAt->getTimestamp(),
            'capabilities' => $this->capabilities,
            'policy_version' => $this->policyVersion,
            'credential_id' => $this->credentialId,
            'credential_fingerprint' => $this->credentialFingerprint,
            'cnf' => ['jwk' => $this->confirmationJwk],
        ];
    }

    /** @param array<string,mixed> $claims */
    public static function fromParsedClaims(array $claims): self
    {
        foreach (RestrictedOfflineSignedGrantContract::CLAIMS as $claim) {
            if (! array_key_exists($claim, $claims)) {
                throw new InvalidArgumentException(
                    'Missing restricted offline grant claim: '.$claim
                );
            }
        }

        $cnf = $claims['cnf'];

        if (
            ! is_array($cnf)
            || array_keys($cnf) !== ['jwk']
            || ! is_array($cnf['jwk'])
        ) {
            throw new InvalidArgumentException('Invalid cnf claim.');
        }

        foreach (['iat', 'nbf', 'exp'] as $dateClaim) {
            if (! $claims[$dateClaim] instanceof DateTimeImmutable) {
                throw new InvalidArgumentException(
                    'Invalid parsed date claim: '.$dateClaim
                );
            }
        }

        if ($claims['nbf']->getTimestamp() !== $claims['iat']->getTimestamp()) {
            throw new InvalidArgumentException('nbf must equal iat.');
        }

        if (! is_int($claims['binding_exp'])) {
            throw new InvalidArgumentException('binding_exp must be an integer.');
        }

        if (! is_array($claims['capabilities'])) {
            throw new InvalidArgumentException('capabilities must be an array.');
        }

        return new self(
            self::requireString($claims['sub'], 'sub'),
            self::requireString($claims['jti'], 'jti'),
            $claims['iat'],
            $claims['exp'],
            self::requirePositiveInt($claims['membership_id'], 'membership_id'),
            self::requirePositiveInt($claims['organization_id'], 'organization_id'),
            self::requireString($claims['device_public_id'], 'device_public_id'),
            self::requireString($claims['binding_public_id'], 'binding_public_id'),
            (new DateTimeImmutable('@'.$claims['binding_exp']))->setTimezone(
                new DateTimeZone('UTC')
            ),
            array_values($claims['capabilities']),
            self::requireString($claims['policy_version'], 'policy_version'),
            self::requireString($claims['credential_id'], 'credential_id'),
            self::requireString(
                $claims['credential_fingerprint'],
                'credential_fingerprint'
            ),
            $cnf['jwk'],
        );
    }

    private function assertSubject(string $subject): void
    {
        $decoded = RestrictedOfflineSignedGrantKeyCodec::decodeBase64Url(
            $subject
        );

        if (strlen($decoded) !== 32) {
            throw new InvalidArgumentException(
                'Passkey opaque user handle must decode to 32 bytes.'
            );
        }
    }

    private function assertUuid(string $value, string $label): void
    {
        if (
            preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di',
                $value
            ) !== 1
        ) {
            throw new InvalidArgumentException('Invalid '.$label.'.');
        }
    }

    private function assertUuidV4(string $value, string $label): void
    {
        if (
            preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di',
                $value
            ) !== 1
        ) {
            throw new InvalidArgumentException('Invalid UUIDv4 '.$label.'.');
        }
    }

    /**
     * @param array<string,mixed> $jwk
     * @return array{alg:string,crv:string,kty:string,x:string,y:string}
     */
    private static function normalizeConfirmationJwk(array $jwk): array
    {
        $keys = array_keys($jwk);
        sort($keys, SORT_STRING);

        if ($keys !== ['alg', 'crv', 'kty', 'x', 'y']) {
            throw new InvalidArgumentException(
                'Confirmation JWK must be public ES256 P-256 only.'
            );
        }

        if (
            $jwk['alg'] !== 'ES256'
            || $jwk['crv'] !== 'P-256'
            || $jwk['kty'] !== 'EC'
            || ! is_string($jwk['x'])
            || ! is_string($jwk['y'])
        ) {
            throw new InvalidArgumentException(
                'Invalid confirmation JWK contract.'
            );
        }

        if (
            strlen(RestrictedOfflineSignedGrantKeyCodec::decodeBase64Url($jwk['x'])) !== 32
            || strlen(RestrictedOfflineSignedGrantKeyCodec::decodeBase64Url($jwk['y'])) !== 32
        ) {
            throw new InvalidArgumentException(
                'P-256 confirmation coordinates must be 32 bytes.'
            );
        }

        return [
            'alg' => 'ES256',
            'crv' => 'P-256',
            'kty' => 'EC',
            'x' => $jwk['x'],
            'y' => $jwk['y'],
        ];
    }

    private static function requireString(mixed $value, string $claim): string
    {
        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException('Invalid '.$claim.' claim.');
        }

        return $value;
    }

    private static function requirePositiveInt(mixed $value, string $claim): int
    {
        if (! is_int($value) || $value < 1) {
            throw new InvalidArgumentException('Invalid '.$claim.' claim.');
        }

        return $value;
    }
}
