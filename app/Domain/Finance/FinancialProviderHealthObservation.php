<?php

namespace App\Domain\Finance;

use App\Enums\FinancialProviderCapability;
use App\Enums\FinancialProviderConnectionHealthStatus;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DomainException;

final readonly class FinancialProviderHealthObservation
{
    public CarbonImmutable $checkedAt;

    public function __construct(
        public FinancialProviderCapability $capability,
        public FinancialProviderConnectionHealthStatus $status,
        DateTimeInterface $checkedAt,
        public string $sourceKey,
        public ?string $diagnosticCode = null,
        public ?int $latencyMs = null
    ) {
        $this->checkedAt = CarbonImmutable::instance(
            $checkedAt
        )->utc();

        $this->assertCode(
            $sourceKey,
            120,
            'La fuente del health check no es válida.'
        );

        if ($diagnosticCode !== null) {
            $this->assertCode(
                $diagnosticCode,
                120,
                'El código diagnóstico del health check no es válido.'
            );
        }

        if (
            $latencyMs !== null
            && ($latencyMs < 0 || $latencyMs > 600000)
        ) {
            throw new DomainException(
                'La latencia del health check no es válida.'
            );
        }
    }

    private function assertCode(
        string $value,
        int $max,
        string $message
    ): void {
        if (
            $value === ''
            || mb_strlen($value) > $max
            || preg_match(
                '/^[a-z0-9][a-z0-9._:-]*$/D',
                $value
            ) !== 1
        ) {
            throw new DomainException($message);
        }
    }
}
