<?php

namespace App\Domain\Fiscal;

use App\Enums\FiscalEnvironment;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;

final class WsaaAccessTicket
{
    public readonly int $organizationId;

    public readonly FiscalEnvironment $environment;

    public readonly string $service;

    public readonly string $issuerCuit;

    public readonly CarbonImmutable $generationTime;

    public readonly CarbonImmutable $expirationTime;

    private readonly string $token;

    private readonly string $sign;

    public function __construct(
        int $organizationId,
        FiscalEnvironment $environment,
        string $service,
        string $issuerCuit,
        string $token,
        string $sign,
        CarbonInterface $generationTime,
        CarbonInterface $expirationTime,
    ) {
        $scope = new WsaaAccessTicketRequest(
            $organizationId,
            $environment,
            $service,
            $issuerCuit
        );

        $token = trim($token);
        $sign = trim($sign);

        if ($token === '' || strlen($token) > 16384) {
            throw new DomainException(
                'Token WSAA vacío o fuera de límite.'
            );
        }

        if ($sign === '' || strlen($sign) > 16384) {
            throw new DomainException(
                'Sign WSAA vacío o fuera de límite.'
            );
        }

        $generated = CarbonImmutable::instance(
            $generationTime
        );

        $expires = CarbonImmutable::instance(
            $expirationTime
        );

        if (! $expires->greaterThan($generated)) {
            throw new DomainException(
                'El Ticket de Acceso WSAA debe vencer después de su generación.'
            );
        }

        $this->organizationId =
            $scope->organizationId;
        $this->environment =
            $scope->environment;
        $this->service =
            $scope->service;
        $this->issuerCuit =
            $scope->issuerCuit;
        $this->generationTime = $generated;
        $this->expirationTime = $expires;
        $this->token = $token;
        $this->sign = $sign;
    }

    public function token(): string
    {
        return $this->token;
    }

    public function sign(): string
    {
        return $this->sign;
    }

    public function assertUsableFor(
        WsaaAccessTicketRequest $request,
        CarbonInterface $at
    ): void {
        if (
            $this->organizationId
                !== $request->organizationId
            || $this->environment
                !== $request->environment
            || $this->service
                !== $request->service
            || $this->issuerCuit
                !== $request->issuerCuit
        ) {
            throw new DomainException(
                'El Ticket de Acceso WSAA no coincide con la organización, ambiente, servicio o CUIT requeridos.'
            );
        }

        $instant = CarbonImmutable::instance($at);

        if (
            $this->generationTime->greaterThan(
                $instant
            )
            || ! $this->expirationTime->greaterThan(
                $instant
            )
        ) {
            throw new DomainException(
                'El Ticket de Acceso WSAA no está vigente en el instante requerido.'
            );
        }
    }

    /**
     * Access tickets are ephemeral secret material.
     *
     * @return never
     */
    public function __serialize(): array
    {
        throw new DomainException(
            'El Ticket de Acceso WSAA no puede serializarse.'
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'organizationId' =>
                $this->organizationId,
            'environment' =>
                $this->environment->value,
            'service' =>
                $this->service,
            'issuerCuit' =>
                $this->issuerCuit,
            'generationTime' =>
                $this->generationTime
                    ->toIso8601String(),
            'expirationTime' =>
                $this->expirationTime
                    ->toIso8601String(),
            'token' => '[REDACTED]',
            'sign' => '[REDACTED]',
        ];
    }
}
