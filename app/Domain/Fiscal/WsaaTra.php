<?php

namespace App\Domain\Fiscal;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;

final readonly class WsaaTra
{
    public const VERSION = '1.0';
    public const MAX_UNIQUE_ID = 4294967295;

    public CarbonImmutable $generationTime;
    public CarbonImmutable $expirationTime;

    private string $xml;

    public function __construct(
        public int $uniqueId,
        CarbonInterface $generationTime,
        CarbonInterface $expirationTime,
        public string $service,
    ) {
        if (
            $this->uniqueId < 0
            || $this->uniqueId > self::MAX_UNIQUE_ID
        ) {
            throw new DomainException(
                'uniqueId WSAA debe ser un unsignedInt de 32 bits.'
            );
        }

        WsaaServiceName::assertValid(
            $this->service
        );

        $generated =
            CarbonImmutable::instance(
                $generationTime
            )->utc();

        $expires =
            CarbonImmutable::instance(
                $expirationTime
            )->utc();

        if (
            ! $expires->greaterThan(
                $generated
            )
        ) {
            throw new DomainException(
                'expirationTime WSAA debe ser posterior a generationTime.'
            );
        }

        $this->generationTime =
            $generated;
        $this->expirationTime =
            $expires;
        $this->xml =
            $this->buildXml();
    }

    public function xml(): string
    {
        return $this->xml;
    }

    private function buildXml(): string
    {
        $generation =
            $this->generationTime->format(
                'Y-m-d\TH:i:sP'
            );

        $expiration =
            $this->expirationTime->format(
                'Y-m-d\TH:i:sP'
            );

        return
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<loginTicketRequest version="'
            . self::VERSION
            . '">'
            . '<header>'
            . '<uniqueId>'
            . $this->uniqueId
            . '</uniqueId>'
            . '<generationTime>'
            . $generation
            . '</generationTime>'
            . '<expirationTime>'
            . $expiration
            . '</expirationTime>'
            . '</header>'
            . '<service>'
            . $this->service
            . '</service>'
            . '</loginTicketRequest>';
    }
}
