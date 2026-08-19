<?php

namespace App\Domain\Fiscal;

use Carbon\CarbonImmutable;
use DomainException;

final readonly class WsaaTraWindowPolicy
{
    public const MAX_SPEC_TOLERANCE_SECONDS = 86400;

    public function __construct(
        public int $generationBackSeconds,
        public int $expirationForwardSeconds,
    ) {
        if (
            $this->generationBackSeconds < 0
            || $this->generationBackSeconds
                > self::MAX_SPEC_TOLERANCE_SECONDS
        ) {
            throw new DomainException(
                'El retroceso de generationTime WSAA debe quedar entre 0 y 86400 segundos.'
            );
        }

        if (
            $this->expirationForwardSeconds <= 0
            || $this->expirationForwardSeconds
                > self::MAX_SPEC_TOLERANCE_SECONDS
        ) {
            throw new DomainException(
                'El adelanto de expirationTime WSAA debe quedar entre 1 y 86400 segundos.'
            );
        }
    }

    public function generationTime(
        CarbonImmutable $now
    ): CarbonImmutable {
        return $now
            ->utc()
            ->subSeconds(
                $this->generationBackSeconds
            );
    }

    public function expirationTime(
        CarbonImmutable $now
    ): CarbonImmutable {
        return $now
            ->utc()
            ->addSeconds(
                $this->expirationForwardSeconds
            );
    }
}
