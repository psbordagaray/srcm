<?php

namespace App\Domain\Service;

final readonly class ServiceQuoteOptionData
{
    /**
     * @param  list<ServiceQuoteLineData>  $lines
     */
    public function __construct(
        public string $label,
        public array $lines,
        public ?string $description = null,
        public bool $recommended = false
    ) {}
}
