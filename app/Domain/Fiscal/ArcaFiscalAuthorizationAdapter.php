<?php

namespace App\Domain\Fiscal;

use App\Enums\FiscalEnvironment;
use App\Models\FiscalDocument;
use DomainException;

final class ArcaFiscalAuthorizationAdapter
{
    private const MAX_WSFE_VOUCHER_NUMBER = 99999999;

    public function __construct(
        private readonly FiscalAuthorizationTransport $transport,
        private readonly FiscalAuthorizationCredentialStore $credentials,
        private readonly FiscalRemoteSequenceAuthority $remoteSequence,
    ) {
    }

    public function request(
        FiscalDocument $document
    ): FiscalAuthorizationTransportResult {
        $point = $document->pointOfSale;

        if (! $point) {
            throw new DomainException(
                'El documento requiere un punto de venta fiscal antes de solicitar autorización.'
            );
        }

        $environment = $point->environment;

        if (! $environment instanceof FiscalEnvironment) {
            throw new DomainException(
                'El ambiente fiscal del punto de venta no es válido.'
            );
        }

        $pointNumber = (int) $point->point_number;

        if ($pointNumber < 1 || $pointNumber > 99998) {
            throw new DomainException(
                'El número externo del punto de venta fiscal debe estar entre 1 y 99998.'
            );
        }

        $classification = $document->classification;
        $rawVoucherType = $classification?->voucher_code;

        if (
            $rawVoucherType === null
            || ! ctype_digit(trim((string) $rawVoucherType))
        ) {
            throw new DomainException(
                'El documento requiere CbteTipo WSFE explícito antes de solicitar autorización.'
            );
        }

        $voucherTypeCode = (int) trim(
            (string) $rawVoucherType
        );

        if ($voucherTypeCode < 1 || $voucherTypeCode > 999) {
            throw new DomainException(
                'CbteTipo WSFE debe estar entre 1 y 999.'
            );
        }

        if (
            ! $this->credentials->configuredFor(
                (int) $document->organization_id
            )
        ) {
            throw new DomainException(
                'La integración fiscal externa no está configurada.'
            );
        }

        $query = new FiscalRemoteSequenceQuery(
            organizationId: (int) $document->organization_id,
            environment: $environment,
            pointOfSaleNumber: $pointNumber,
            voucherTypeCode: $voucherTypeCode,
        );

        $state = $this->remoteSequence->lastAuthorized(
            $query
        );

        $this->assertMatchingRemoteState(
            $query,
            $state
        );

        if (
            $state->lastAuthorizedNumber < 0
            || $state->lastAuthorizedNumber
                >= self::MAX_WSFE_VOUCHER_NUMBER
        ) {
            throw new DomainException(
                'La autoridad remota devolvió un último CbteNro fuera del rango que permite derivar el siguiente comprobante.'
            );
        }

        $nextVoucherNumber =
            $state->lastAuthorizedNumber + 1;

        return $this->transport->authorize(
            new FiscalAuthorizationTransportRequest(
                fiscalDocumentId: (int) $document->id,
                environment: $environment,
                pointOfSaleNumber: $pointNumber,
                voucherTypeCode: $voucherTypeCode,
                voucherNumber: $nextVoucherNumber,
            )
        );
    }

    private function assertMatchingRemoteState(
        FiscalRemoteSequenceQuery $query,
        FiscalRemoteSequenceState $state
    ): void {
        if (
            $state->environment !== $query->environment
            || $state->pointOfSaleNumber
                !== $query->pointOfSaleNumber
            || $state->voucherTypeCode
                !== $query->voucherTypeCode
        ) {
            throw new DomainException(
                'La autoridad remota respondió para otra identidad WSFE de secuencia.'
            );
        }
    }
}
