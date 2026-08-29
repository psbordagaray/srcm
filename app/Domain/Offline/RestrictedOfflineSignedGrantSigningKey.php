<?php

declare(strict_types=1);

namespace App\Domain\Offline;

final readonly class RestrictedOfflineSignedGrantSigningKey
{
    public string $publicKey;

    public function __construct(
        public string $kid,
        public string $secretKey,
    ) {
        RestrictedOfflineSignedGrantContract::assertKid($kid);
        RestrictedOfflineSignedGrantKeyCodec::signingKey($secretKey);

        $this->publicKey = sodium_crypto_sign_publickey_from_secretkey(
            $secretKey
        );
    }
}
