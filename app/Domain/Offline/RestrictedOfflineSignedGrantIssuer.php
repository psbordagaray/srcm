<?php

declare(strict_types=1);

namespace App\Domain\Offline;

use InvalidArgumentException;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\UnixTimestampDates;
use Lcobucci\JWT\Signer\Eddsa;

final class RestrictedOfflineSignedGrantIssuer
{
    private readonly Configuration $configuration;

    public function __construct(
        private readonly string $kid,
        string $secretKey,
    ) {
        RestrictedOfflineSignedGrantContract::assertKid($kid);
        $signingKey = RestrictedOfflineSignedGrantKeyCodec::signingKey(
            $secretKey
        );
        $publicKey = sodium_crypto_sign_publickey_from_secretkey($secretKey);

        $this->configuration = Configuration::forAsymmetricSigner(
            new Eddsa,
            $signingKey,
            RestrictedOfflineSignedGrantKeyCodec::verificationKey($publicKey)
        );
    }

    public function issue(RestrictedOfflineSignedGrantClaims $claims): string
    {
        $builder = $this->configuration
            ->builder(new ChainedFormatter(new UnixTimestampDates))
            ->withHeader('typ', RestrictedOfflineSignedGrantContract::TYPE)
            ->withHeader('kid', $this->kid)
            ->issuedBy(RestrictedOfflineSignedGrantContract::ISSUER)
            ->permittedFor(RestrictedOfflineSignedGrantContract::AUDIENCE)
            ->relatedTo($claims->subject)
            ->identifiedBy($claims->jti)
            ->issuedAt($claims->issuedAt)
            ->canOnlyBeUsedAfter($claims->issuedAt)
            ->expiresAt($claims->expiresAt);

        foreach ($claims->privateClaims() as $name => $value) {
            $builder = $builder->withClaim($name, $value);
        }

        $compact = $builder
            ->getToken(
                $this->configuration->signer(),
                $this->configuration->signingKey()
            )
            ->toString();

        RestrictedOfflineSignedGrantContract::assertCompact($compact);

        if (substr_count($compact, '.') !== 2) {
            throw new InvalidArgumentException(
                'Restricted offline grant must be compact JWS.'
            );
        }

        return $compact;
    }
}
