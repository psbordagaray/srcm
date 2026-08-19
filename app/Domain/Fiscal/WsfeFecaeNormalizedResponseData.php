<?php

namespace App\Domain\Fiscal;

use App\Enums\FiscalAuthorizationOutcome;
use DomainException;

final readonly class WsfeFecaeNormalizedResponseData
{
    /**
     * @param array<string,mixed> $observations
     * @param array<string,mixed> $events
     * @param array<string,mixed> $errors
     * @param array<string,mixed> $preservedResult
     */
    public function __construct(
        public FiscalAuthorizationOutcome $outcome,
        public ?string $headerResultCode,
        public ?string $detailResultCode,
        public ?string $cae,
        public ?string $caeExpiration,
        public array $observations,
        public array $events,
        public array $errors,
        private array $preservedResult,
    ) {
        if ($this->preservedResult === []) {
            throw new DomainException(
                'La respuesta WSFE normalizada debe conservar el resultado provider original.'
            );
        }

        if (
            $this->outcome
                === FiscalAuthorizationOutcome::Authorized
            && (
                $this->headerResultCode !== 'A'
                || $this->detailResultCode !== 'A'
                || $this->cae === null
                || $this->cae === ''
                || $this->caeExpiration === null
                || $this->caeExpiration === ''
            )
        ) {
            throw new DomainException(
                'Una normalización autorizada requiere Resultado A consistente, CAE y vencimiento.'
            );
        }

        if (
            $this->outcome
                === FiscalAuthorizationOutcome::Rejected
            && (
                $this->headerResultCode !== 'R'
                || $this->detailResultCode !== 'R'
                || (
                    $this->cae !== null
                    && $this->cae !== ''
                )
            )
        ) {
            throw new DomainException(
                'Una normalización rechazada requiere Resultado R consistente y ausencia de CAE.'
            );
        }
    }

    /**
     * Preserves every provider field, including unknown additions.
     *
     * @return array<string,mixed>
     */
    public function preservedResult(): array
    {
        return $this->preservedResult;
    }
}
