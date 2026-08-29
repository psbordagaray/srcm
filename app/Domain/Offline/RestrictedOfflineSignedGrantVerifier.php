<?php

declare(strict_types=1);

namespace App\Domain\Offline;

use DateTimeImmutable;
use InvalidArgumentException;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Eddsa;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Validator;
use Throwable;

final class RestrictedOfflineSignedGrantVerifier
{
    /** @var array<string,string> */
    private array $publicKeyring;

    /** @param array<string,string> $publicKeyring kid => raw Ed25519 public key */
    public function __construct(array $publicKeyring)
    {
        if ($publicKeyring === []) {
            throw new InvalidArgumentException(
                'Restricted offline grant public keyring cannot be empty.'
            );
        }

        foreach ($publicKeyring as $kid => $publicKey) {
            if (! is_string($kid) || ! is_string($publicKey)) {
                throw new InvalidArgumentException('Invalid public keyring entry.');
            }

            RestrictedOfflineSignedGrantContract::assertKid($kid);
            RestrictedOfflineSignedGrantKeyCodec::verificationKey($publicKey);
        }

        $this->publicKeyring = $publicKeyring;
    }

    public function verify(
        string $compact,
        ?DateTimeImmutable $now = null,
    ): RestrictedOfflineSignedGrantClaims {
        RestrictedOfflineSignedGrantContract::assertCompact($compact);

        try {
            $token = (new Parser(new JoseEncoder))->parse($compact);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException(
                'Restricted offline grant structure is invalid.',
                0,
                $exception
            );
        }

        if (! $token instanceof UnencryptedToken) {
            throw new InvalidArgumentException(
                'Restricted offline grant must be a plain signed token.'
            );
        }

        $headers = $token->headers()->all();
        $headerKeys = array_keys($headers);
        sort($headerKeys, SORT_STRING);

        if ($headerKeys !== ['alg', 'kid', 'typ']) {
            throw new InvalidArgumentException(
                'Restricted offline grant header is not exact.'
            );
        }

        if (
            $headers['alg'] !== RestrictedOfflineSignedGrantContract::ALGORITHM
            || $headers['typ'] !== RestrictedOfflineSignedGrantContract::TYPE
            || ! is_string($headers['kid'])
        ) {
            throw new InvalidArgumentException(
                'Restricted offline grant header values are invalid.'
            );
        }

        $kid = $headers['kid'];
        RestrictedOfflineSignedGrantContract::assertKid($kid);

        if (! array_key_exists($kid, $this->publicKeyring)) {
            throw new InvalidArgumentException('Unknown offline grant kid.');
        }

        try {
            (new Validator)->assert(
                $token,
                new SignedWith(
                    new Eddsa,
                    RestrictedOfflineSignedGrantKeyCodec::verificationKey(
                        $this->publicKeyring[$kid]
                    )
                )
            );
        } catch (Throwable $exception) {
            throw new InvalidArgumentException(
                'Restricted offline grant signature is invalid.',
                0,
                $exception
            );
        }

        $claims = $token->claims()->all();
        $claimKeys = array_keys($claims);
        sort($claimKeys, SORT_STRING);
        $expectedClaimKeys = RestrictedOfflineSignedGrantContract::CLAIMS;
        sort($expectedClaimKeys, SORT_STRING);

        if ($claimKeys !== $expectedClaimKeys) {
            throw new InvalidArgumentException(
                'Restricted offline grant claim set is not exact.'
            );
        }

        if (
            $claims['iss'] !== RestrictedOfflineSignedGrantContract::ISSUER
            || $claims['aud'] !== [RestrictedOfflineSignedGrantContract::AUDIENCE]
            || $claims['srcm_ver'] !== RestrictedOfflineSignedGrantContract::VERSION
        ) {
            throw new InvalidArgumentException(
                'Restricted offline grant issuer, audience, or version is invalid.'
            );
        }

        $parsed = RestrictedOfflineSignedGrantClaims::fromParsedClaims($claims);
        $clock = $now ?? new DateTimeImmutable('now');
        $nowTs = $clock->getTimestamp();
        $skew = RestrictedOfflineSignedGrantContract::CLOCK_SKEW_SECONDS;

        if ($parsed->issuedAt->getTimestamp() > $nowTs + $skew) {
            throw new InvalidArgumentException(
                'Restricted offline grant issued-at is in the future.'
            );
        }

        if ($nowTs >= $parsed->expiresAt->getTimestamp() + $skew) {
            throw new InvalidArgumentException(
                'Restricted offline grant is expired.'
            );
        }

        return $parsed;
    }
}
