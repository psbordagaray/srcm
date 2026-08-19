<?php

namespace App\Domain\Fiscal;

final class WsfeFecaeProviderResultConvergence implements
    WsfeFecaeProviderResultConvergenceContract
{
    public const PROVIDER = 'arca_wsfe_v1';

    public function converge(
        WsfeFecaeNormalizedResponseData $response
    ): FiscalAuthorizationTransportResult {
        return new FiscalAuthorizationTransportResult(
            outcome: $response->outcome,
            resultCode:
                $response->headerResultCode
                ?? $response->detailResultCode,
            authorizationCode: $response->cae,
            authorizationCodeExpiresOn:
                $this->isoDate(
                    $response->caeExpiration
                ),
            providerEvidence: [
                'provider' => self::PROVIDER,
                'header_result_code' =>
                    $response->headerResultCode,
                'detail_result_code' =>
                    $response->detailResultCode,
                'observations' =>
                    $response->observations,
                'events' =>
                    $response->events,
                'errors' =>
                    $response->errors,
                'preserved_result' =>
                    $response->preservedResult(),
            ],
        );
    }

    private function isoDate(
        ?string $value
    ): ?string {
        if (
            $value === null
            || preg_match(
                '/^[0-9]{8}$/D',
                $value
            ) !== 1
        ) {
            return null;
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

        if (
            ! checkdate(
                $month,
                $day,
                $year
            )
        ) {
            return null;
        }

        return sprintf(
            '%04d-%02d-%02d',
            $year,
            $month,
            $day
        );
    }
}
