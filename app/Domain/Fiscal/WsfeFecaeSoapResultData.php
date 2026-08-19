<?php

namespace App\Domain\Fiscal;

use DomainException;

final readonly class WsfeFecaeSoapResultData
{
    /**
     * @param array<string,mixed> $preservedResult
     */
    public function __construct(
        private array $preservedResult
    ) {
        if ($this->preservedResult === []) {
            throw new DomainException(
                'El resultado FECAESolicitar no puede estar vacío.'
            );
        }

        $knownSectionFound = false;

        foreach (
            [
                'FeCabResp',
                'FeDetResp',
                'Events',
                'Errors',
            ]
            as $section
        ) {
            if (
                ! array_key_exists(
                    $section,
                    $this->preservedResult
                )
            ) {
                continue;
            }

            $knownSectionFound = true;

            if (
                ! is_array(
                    $this->preservedResult[$section]
                )
            ) {
                throw new DomainException(
                    "La sección {$section} del resultado FECAE debe preservarse como array."
                );
            }
        }

        if (! $knownSectionFound) {
            throw new DomainException(
                'El resultado FECAESolicitar no contiene ninguna sección reconocible.'
            );
        }
    }

    /**
     * Full provider result, including unknown future fields.
     *
     * @return array<string,mixed>
     */
    public function preservedResult(): array
    {
        return $this->preservedResult;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function header(): ?array
    {
        return $this->section('FeCabResp');
    }

    /**
     * Keeps FECAEDetResponse, CAE, CAEFchVto,
     * Observaciones and any provider additions intact.
     *
     * @return array<string,mixed>|null
     */
    public function detailSection(): ?array
    {
        return $this->section('FeDetResp');
    }

    /**
     * @return array<string,mixed>|null
     */
    public function events(): ?array
    {
        return $this->section('Events');
    }

    /**
     * @return array<string,mixed>|null
     */
    public function errors(): ?array
    {
        return $this->section('Errors');
    }

    /**
     * @return array<string,mixed>|null
     */
    private function section(
        string $name
    ): ?array {
        $value = $this->preservedResult[$name]
            ?? null;

        return is_array($value)
            ? $value
            : null;
    }
}
