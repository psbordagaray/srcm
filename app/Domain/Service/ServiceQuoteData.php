<?php

namespace App\Domain\Service;

use Carbon\CarbonImmutable;

final readonly class ServiceQuoteData
{
    /**
     * @param  list<ServiceQuoteOptionData>  $options
     */
    public function __construct(
        public int $serviceOrderId,
        public array $options,
        public string $idempotencyKey,
        public string $currencyCode = 'ARS',
        public ?CarbonImmutable $validUntil = null,
        public ?string $terms = null
    ) {}
}
