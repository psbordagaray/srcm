<?php

namespace App\Adapters\Fiscal\Arca;

use App\Domain\Fiscal\WsaaAccessTicket;
use App\Domain\Fiscal\WsaaAccessTicketProvider;
use App\Domain\Fiscal\WsaaAccessTicketRequest;
use App\Domain\Fiscal\WsaaCmsDigestPolicy;
use App\Domain\Fiscal\WsaaCmsSigner;
use App\Domain\Fiscal\WsaaCredentialMaterialProvider;
use App\Domain\Fiscal\WsaaLoginCmsTransport;
use App\Domain\Fiscal\WsaaTraBuilder;
use App\Domain\Fiscal\WsaaTraClock;
use App\Enums\FiscalEnvironment;
use Carbon\CarbonImmutable;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Encryption\Encrypter;
use RuntimeException;
use Throwable;

final class EncryptedCacheWsaaAccessTicketProvider implements
    WsaaAccessTicketProvider
{
    public const CACHE_GRACE_AFTER_EXPIRATION_SECONDS = 300;
    public const LOCK_SECONDS = 60;
    public const LOCK_WAIT_SECONDS = 20;

    private const CACHE_KEY_PREFIX = 'srcm:wsaa:ta:v1:';
    private const LOCK_KEY_PREFIX = 'srcm:wsaa:ta-lock:v1:';
    private const ENVELOPE_VERSION = 1;

    public function __construct(
        private readonly Repository $cache,
        private readonly Encrypter $encrypter,
        private readonly WsaaTraClock $clock,
        private readonly WsaaTraBuilder $traBuilder,
        private readonly WsaaCredentialMaterialProvider $materials,
        private readonly WsaaCmsDigestPolicy $digestPolicy,
        private readonly WsaaCmsSigner $signer,
        private readonly WsaaLoginCmsTransport $transport,
    ) {
    }

    public function ticketFor(
        WsaaAccessTicketRequest $request
    ): WsaaAccessTicket {
        if ($request->environment !== FiscalEnvironment::Homologation) {
            throw new RuntimeException(
                'La obtención automática de Ticket de Acceso WSAA en producción permanece bloqueada.'
            );
        }

        $now = $this->clock->now()->utc();
        $cached = $this->readCachedTicket($request, $now);

        if ($cached instanceof WsaaAccessTicket) {
            return $cached;
        }

        $lock = $this->cache->lock(
            $this->lockKey($request),
            self::LOCK_SECONDS
        );

        try {
            return $lock->block(
                self::LOCK_WAIT_SECONDS,
                function () use ($request): WsaaAccessTicket {
                    $insideNow = $this->clock->now()->utc();
                    $insideCached = $this->readCachedTicket(
                        $request,
                        $insideNow
                    );

                    if ($insideCached instanceof WsaaAccessTicket) {
                        return $insideCached;
                    }

                    $digest = $this->digestPolicy->forEnvironment(
                        $request->environment
                    );
                    $tra = $this->traBuilder->build($request);
                    $material = $this->materials->forRequest($request);
                    $signedCms = $this->signer->sign(
                        $tra,
                        $material,
                        $digest
                    );
                    $ticket = $this->transport->exchange(
                        $request,
                        $signedCms
                    );

                    $validatedAt = $this->clock->now()->utc();
                    $ticket->assertUsableFor($request, $validatedAt);
                    $this->storeTicket(
                        $request,
                        $ticket,
                        $validatedAt
                    );

                    return $ticket;
                }
            );
        } catch (LockTimeoutException $exception) {
            throw new RuntimeException(
                'No se pudo coordinar la obtención del Ticket de Acceso WSAA para este scope.',
                0,
                $exception
            );
        }
    }

    private function readCachedTicket(
        WsaaAccessTicketRequest $request,
        CarbonImmutable $now
    ): ?WsaaAccessTicket {
        $ciphertext = $this->cache->get(
            $this->cacheKey($request)
        );

        if ($ciphertext === null) {
            return null;
        }

        if (! is_string($ciphertext) || $ciphertext === '') {
            throw new RuntimeException(
                'El cache WSAA contiene un envelope inválido; no se solicitará un nuevo Ticket de Acceso automáticamente.'
            );
        }

        try {
            $plaintext = $this->encrypter->decrypt(
                $ciphertext,
                false
            );

            if (! is_string($plaintext) || $plaintext === '') {
                throw new RuntimeException('invalid plaintext');
            }

            $payload = json_decode(
                $plaintext,
                true,
                32,
                JSON_THROW_ON_ERROR
            );

            if (! is_array($payload)) {
                throw new RuntimeException('invalid payload');
            }

            $expectedScope = $this->scopeArray($request);

            if (
                ($payload['v'] ?? null) !== self::ENVELOPE_VERSION
                || ($payload['scope'] ?? null) !== $expectedScope
                || ! is_string($payload['token'] ?? null)
                || ! is_string($payload['sign'] ?? null)
                || ! is_string($payload['generation_time'] ?? null)
                || ! is_string($payload['expiration_time'] ?? null)
            ) {
                throw new RuntimeException('invalid envelope');
            }

            $ticket = new WsaaAccessTicket(
                $request->organizationId,
                $request->environment,
                $request->service,
                $request->issuerCuit,
                $payload['token'],
                $payload['sign'],
                CarbonImmutable::parse(
                    $payload['generation_time'],
                    'UTC'
                ),
                CarbonImmutable::parse(
                    $payload['expiration_time'],
                    'UTC'
                ),
            );

            if (! $ticket->expirationTime->greaterThan($now)) {
                return null;
            }

            $ticket->assertUsableFor($request, $now);

            return $ticket;
        } catch (Throwable $exception) {
            if (
                $exception instanceof RuntimeException
                && str_starts_with(
                    $exception->getMessage(),
                    'El cache WSAA contiene'
                )
            ) {
                throw $exception;
            }

            throw new RuntimeException(
                'El cache WSAA no pudo descifrarse o validarse; no se solicitará un nuevo Ticket de Acceso automáticamente.',
                0,
                $exception
            );
        }
    }

    private function storeTicket(
        WsaaAccessTicketRequest $request,
        WsaaAccessTicket $ticket,
        CarbonImmutable $now
    ): void {
        $payload = json_encode(
            [
                'v' => self::ENVELOPE_VERSION,
                'scope' => $this->scopeArray($request),
                'generation_time' => $ticket->generationTime
                    ->utc()
                    ->toIso8601String(),
                'expiration_time' => $ticket->expirationTime
                    ->utc()
                    ->toIso8601String(),
                'token' => $ticket->token(),
                'sign' => $ticket->sign(),
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        );

        $ciphertext = $this->encrypter->encrypt(
            $payload,
            false
        );

        if (
            ! is_string($ciphertext)
            || $ciphertext === ''
            || str_contains($ciphertext, $ticket->token())
            || str_contains($ciphertext, $ticket->sign())
        ) {
            throw new RuntimeException(
                'No se pudo producir un envelope cifrado seguro para el Ticket de Acceso WSAA.'
            );
        }

        $ttl =
            $ticket->expirationTime->getTimestamp()
            - $now->getTimestamp()
            + self::CACHE_GRACE_AFTER_EXPIRATION_SECONDS;

        if ($ttl <= self::CACHE_GRACE_AFTER_EXPIRATION_SECONDS) {
            throw new RuntimeException(
                'El Ticket de Acceso WSAA nuevo no posee una vigencia almacenable.'
            );
        }

        if (! $this->cache->put(
            $this->cacheKey($request),
            $ciphertext,
            $ttl
        )) {
            throw new RuntimeException(
                'El Ticket de Acceso WSAA fue obtenido pero no pudo persistirse de forma segura; no se devolverá ni se reintentará automáticamente.'
            );
        }
    }

    /** @return array{organization_id:int,environment:string,service:string,issuer_cuit:string} */
    private function scopeArray(
        WsaaAccessTicketRequest $request
    ): array {
        return [
            'organization_id' => $request->organizationId,
            'environment' => $request->environment->value,
            'service' => $request->service,
            'issuer_cuit' => $request->issuerCuit,
        ];
    }

    private function scopeHash(
        WsaaAccessTicketRequest $request
    ): string {
        return hash(
            'sha256',
            json_encode(
                $this->scopeArray($request),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
            )
        );
    }

    private function cacheKey(
        WsaaAccessTicketRequest $request
    ): string {
        return self::CACHE_KEY_PREFIX . $this->scopeHash($request);
    }

    private function lockKey(
        WsaaAccessTicketRequest $request
    ): string {
        return self::LOCK_KEY_PREFIX . $this->scopeHash($request);
    }
}
