<?php

namespace App\Domain\Fiscal;

use App\Enums\FiscalAuthorizationOutcome;
use DomainException;

final class WsfeFecaeProviderResponseNormalizer implements
    WsfeFecaeProviderResponseNormalizerContract
{
    public function normalize(
        WsfeFecaeSoapResultData $result
    ): WsfeFecaeNormalizedResponseData {
        $header = $result->header();
        $detail = $this->singleDetail(
            $result->detailSection()
        );

        $headerResult = $this->optionalCode(
            $header,
            'Resultado',
            'FeCabResp.Resultado'
        );

        $detailResult = $this->optionalCode(
            $detail,
            'Resultado',
            'FECAEDetResponse.Resultado'
        );

        $cae = $this->optionalString(
            $detail,
            'CAE',
            'FECAEDetResponse.CAE'
        );

        $caeExpiration = $this->optionalString(
            $detail,
            'CAEFchVto',
            'FECAEDetResponse.CAEFchVto'
        );

        $observations = $this->optionalArray(
            $detail,
            'Observaciones',
            'FECAEDetResponse.Observaciones'
        );

        $events = $result->events() ?? [];
        $errors = $result->errors() ?? [];

        $outcome = $this->outcome(
            $headerResult,
            $detailResult,
            $cae,
            $caeExpiration
        );

        return new WsfeFecaeNormalizedResponseData(
            outcome: $outcome,
            headerResultCode: $headerResult,
            detailResultCode: $detailResult,
            cae: $cae,
            caeExpiration: $caeExpiration,
            observations: $observations,
            events: $events,
            errors: $errors,
            preservedResult: $result->preservedResult(),
        );
    }

    /**
     * V1 composes CantReg=1, so provider normalization accepts
     * exactly one detail response whenever FeDetResp is present.
     *
     * @param array<string,mixed>|null $detailSection
     * @return array<string,mixed>|null
     */
    private function singleDetail(
        ?array $detailSection
    ): ?array {
        if ($detailSection === null) {
            return null;
        }

        if (
            ! array_key_exists(
                'FECAEDetResponse',
                $detailSection
            )
        ) {
            return null;
        }

        $raw = $detailSection['FECAEDetResponse'];

        if (! is_array($raw)) {
            throw new DomainException(
                'FeDetResp.FECAEDetResponse debe preservarse como array.'
            );
        }

        if ($raw === []) {
            return null;
        }

        if (! array_is_list($raw)) {
            return $raw;
        }

        if (
            count($raw) !== 1
            || ! is_array($raw[0])
        ) {
            throw new DomainException(
                'La frontera V1 admite exactamente un FECAEDetResponse.'
            );
        }

        return $raw[0];
    }

    /**
     * @param array<string,mixed>|null $source
     */
    private function optionalCode(
        ?array $source,
        string $key,
        string $label
    ): ?string {
        $value = $this->optionalString(
            $source,
            $key,
            $label
        );

        return $value === null
            ? null
            : strtoupper($value);
    }

    /**
     * @param array<string,mixed>|null $source
     */
    private function optionalString(
        ?array $source,
        string $key,
        string $label
    ): ?string {
        if (
            $source === null
            || ! array_key_exists($key, $source)
            || $source[$key] === null
        ) {
            return null;
        }

        if (
            ! is_string($source[$key])
            && ! is_int($source[$key])
        ) {
            throw new DomainException(
                "{$label} debe ser escalar normalizable."
            );
        }

        $value = trim(
            (string) $source[$key]
        );

        return $value === ''
            ? null
            : $value;
    }

    /**
     * @param array<string,mixed>|null $source
     * @return array<string,mixed>
     */
    private function optionalArray(
        ?array $source,
        string $key,
        string $label
    ): array {
        if (
            $source === null
            || ! array_key_exists($key, $source)
            || $source[$key] === null
        ) {
            return [];
        }

        if (! is_array($source[$key])) {
            throw new DomainException(
                "{$label} debe preservarse como array."
            );
        }

        return $source[$key];
    }

    private function outcome(
        ?string $headerResult,
        ?string $detailResult,
        ?string $cae,
        ?string $caeExpiration
    ): FiscalAuthorizationOutcome {
        if (
            $headerResult === 'A'
            && $detailResult === 'A'
            && $this->validCae($cae)
            && $this->validProviderDate(
                $caeExpiration
            )
        ) {
            return FiscalAuthorizationOutcome::Authorized;
        }

        if (
            $headerResult === 'R'
            && $detailResult === 'R'
            && $cae === null
        ) {
            return FiscalAuthorizationOutcome::Rejected;
        }

        return FiscalAuthorizationOutcome::Unknown;
    }

    private function validCae(
        ?string $cae
    ): bool {
        return $cae !== null
            && preg_match(
                '/^[0-9]+$/D',
                $cae
            ) === 1;
    }

    private function validProviderDate(
        ?string $value
    ): bool {
        if (
            $value === null
            || preg_match(
                '/^[0-9]{8}$/D',
                $value
            ) !== 1
        ) {
            return false;
        }

        $year = (int) substr(
            $value,
            0,
            4
        );

        $month = (int) substr(
            $value,
            4,
            2
        );

        $day = (int) substr(
            $value,
            6,
            2
        );

        return checkdate(
            $month,
            $day,
            $year
        );
    }
}
