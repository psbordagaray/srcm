<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Domain\Offline\RestrictedOfflineSignedGrantClaims;
use App\Domain\Offline\RestrictedOfflineSignedGrantContract;
use App\Domain\Offline\RestrictedOfflineSignedGrantIssuer;
use App\Domain\Offline\RestrictedOfflineSignedGrantKeyCodec;
use App\Domain\Offline\RestrictedOfflineSignedGrantVerifier;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RestrictedOfflineSignedGrantCryptoFoundationTest extends TestCase
{
    #[Test]
    public function server_issuer_and_verifier_round_trip_exact_contract(): void
    {
        [$secretKey, $publicKey] = $this->ed25519Keypair();
        $claims = $this->claims();
        $issuer = new RestrictedOfflineSignedGrantIssuer('offline-k1', $secretKey);
        $compact = $issuer->issue($claims);

        self::assertLessThanOrEqual(
            RestrictedOfflineSignedGrantContract::MAX_COMPACT_BYTES,
            strlen($compact)
        );
        self::assertSame(2, substr_count($compact, '.'));

        $verified = (new RestrictedOfflineSignedGrantVerifier([
            'offline-k1' => $publicKey,
        ]))->verify(
            $compact,
            new DateTimeImmutable('2026-08-29T03:30:00+00:00')
        );

        self::assertSame($claims->jti, $verified->jti);
        self::assertSame($claims->subject, $verified->subject);
        self::assertSame(
            ['restricted_offline_read_model', 'restricted_offline_replay'],
            $verified->capabilities
        );

        [$header, $payload] = array_slice(explode('.', $compact), 0, 2);
        $decodedHeader = json_decode(
            RestrictedOfflineSignedGrantKeyCodec::decodeBase64Url($header),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $decodedPayload = json_decode(
            RestrictedOfflineSignedGrantKeyCodec::decodeBase64Url($payload),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $headerKeys = array_keys($decodedHeader);
        sort($headerKeys, SORT_STRING);
        self::assertSame(['alg', 'kid', 'typ'], $headerKeys);
        self::assertSame('EdDSA', $decodedHeader['alg']);
        self::assertSame('SRCM-OFFLINE-GRANT', $decodedHeader['typ']);
        self::assertSame('offline-k1', $decodedHeader['kid']);
        self::assertSame(
            [RestrictedOfflineSignedGrantContract::AUDIENCE],
            $decodedPayload['aud']
        );
        self::assertIsInt($decodedPayload['iat']);
        self::assertIsInt($decodedPayload['nbf']);
        self::assertIsInt($decodedPayload['exp']);
        self::assertArrayNotHasKey('name', $decodedPayload);
        self::assertArrayNotHasKey('email', $decodedPayload);
        self::assertArrayNotHasKey('password', $decodedPayload);
        self::assertArrayNotHasKey('customer', $decodedPayload);
        self::assertArrayNotHasKey('payment', $decodedPayload);
        self::assertArrayNotHasKey('fiscal', $decodedPayload);
    }

    #[Test]
    public function tampering_and_unknown_kid_fail_closed(): void
    {
        [$secretKey, $publicKey] = $this->ed25519Keypair();
        $compact = (new RestrictedOfflineSignedGrantIssuer(
            'offline-k1',
            $secretKey
        ))->issue($this->claims());
        $verifier = new RestrictedOfflineSignedGrantVerifier([
            'offline-k1' => $publicKey,
        ]);

        [$header, $payload, $signature] = explode('.', $compact);
        $payloadJson = json_decode(
            RestrictedOfflineSignedGrantKeyCodec::decodeBase64Url($payload),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $payloadJson['organization_id'] = 999;
        $tamperedPayload = RestrictedOfflineSignedGrantKeyCodec::encodeBase64Url(
            json_encode($payloadJson, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );

        $this->expectException(InvalidArgumentException::class);
        $verifier->verify(
            $header.'.'.$tamperedPayload.'.'.$signature,
            new DateTimeImmutable('2026-08-29T03:30:00+00:00')
        );
    }

    #[Test]
    public function unknown_kid_is_rejected_before_any_token_supplied_trust_anchor(): void
    {
        [$secretKey] = $this->ed25519Keypair();
        [, $otherPublicKey] = $this->ed25519Keypair();
        $compact = (new RestrictedOfflineSignedGrantIssuer(
            'offline-k1',
            $secretKey
        ))->issue($this->claims());

        $this->expectException(InvalidArgumentException::class);
        (new RestrictedOfflineSignedGrantVerifier([
            'offline-k2' => $otherPublicKey,
        ]))->verify(
            $compact,
            new DateTimeImmutable('2026-08-29T03:30:00+00:00')
        );
    }

    #[Test]
    public function ttl_binding_and_confirmation_key_contract_fail_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RestrictedOfflineSignedGrantClaims(
            $this->opaqueSubject(),
            '11111111-1111-4111-8111-111111111111',
            new DateTimeImmutable('2026-08-29T03:00:00+00:00'),
            new DateTimeImmutable('2026-08-29T12:00:01+00:00'),
            1,
            1,
            '22222222-2222-4222-8222-222222222222',
            '33333333-3333-4333-8333-333333333333',
            new DateTimeImmutable('2026-08-29T13:00:00+00:00'),
            ['restricted_offline_read_model'],
            'offline-v1',
            RestrictedOfflineSignedGrantKeyCodec::encodeBase64Url('credential'),
            str_repeat('a', 64),
            $this->confirmationJwk(),
        );
    }

    #[Test]
    public function expired_grant_fails_closed_with_only_contract_clock_skew(): void
    {
        [$secretKey, $publicKey] = $this->ed25519Keypair();
        $compact = (new RestrictedOfflineSignedGrantIssuer(
            'offline-k1',
            $secretKey
        ))->issue($this->claims());

        $this->expectException(InvalidArgumentException::class);
        (new RestrictedOfflineSignedGrantVerifier([
            'offline-k1' => $publicKey,
        ]))->verify(
            $compact,
            new DateTimeImmutable('2026-08-29T07:02:00+00:00')
        );
    }

    /** @return array{0:string,1:string} */
    private function ed25519Keypair(): array
    {
        $keypair = sodium_crypto_sign_keypair();

        return [
            sodium_crypto_sign_secretkey($keypair),
            sodium_crypto_sign_publickey($keypair),
        ];
    }

    private function claims(): RestrictedOfflineSignedGrantClaims
    {
        return new RestrictedOfflineSignedGrantClaims(
            $this->opaqueSubject(),
            '11111111-1111-4111-8111-111111111111',
            new DateTimeImmutable('2026-08-29T03:00:00+00:00'),
            new DateTimeImmutable('2026-08-29T07:00:00+00:00'),
            17,
            9,
            '22222222-2222-4222-8222-222222222222',
            '33333333-3333-4333-8333-333333333333',
            new DateTimeImmutable('2026-08-29T08:00:00+00:00'),
            [
                'restricted_offline_replay',
                'restricted_offline_read_model',
            ],
            'offline-v1',
            RestrictedOfflineSignedGrantKeyCodec::encodeBase64Url('credential-1'),
            str_repeat('a', 64),
            $this->confirmationJwk(),
        );
    }

    private function opaqueSubject(): string
    {
        return RestrictedOfflineSignedGrantKeyCodec::encodeBase64Url(
            hash('sha256', 'opaque-passkey-user-handle', true)
        );
    }

    /** @return array{alg:string,crv:string,kty:string,x:string,y:string} */
    private function confirmationJwk(): array
    {
        return [
            'alg' => 'ES256',
            'crv' => 'P-256',
            'kty' => 'EC',
            'x' => RestrictedOfflineSignedGrantKeyCodec::encodeBase64Url(
                str_repeat("\x11", 32)
            ),
            'y' => RestrictedOfflineSignedGrantKeyCodec::encodeBase64Url(
                str_repeat("\x22", 32)
            ),
        ];
    }
}
