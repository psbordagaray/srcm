<?php

namespace App\Adapters\Finance\MercadoPago;

use DomainException;

final class MercadoPagoWebhookSignatureVerifier
{
    public function verify(
        string $xSignature,
        string $xRequestId,
        string $dataId,
        string $secret
    ): void {
        $secret = trim($secret);

        if ($secret === '' || strlen($secret) > 1024) {
            throw new DomainException(
                'La clave transitoria del webhook de Mercado Pago no es válida.'
            );
        }

        $requestId = trim($xRequestId);
        $resourceId = trim($dataId);

        if (
            $requestId === ''
            || strlen($requestId) > 191
            || preg_match('/^[A-Za-z0-9._:-]+$/D', $requestId) !== 1
        ) {
            throw new DomainException(
                'Mercado Pago webhook no informa un x-request-id válido.'
            );
        }

        if (
            $resourceId === ''
            || strlen($resourceId) > 191
            || preg_match('/^[A-Za-z0-9._:-]+$/D', $resourceId) !== 1
        ) {
            throw new DomainException(
                'Mercado Pago webhook no informa un data.id válido.'
            );
        }

        [$timestamp, $signature] = $this->signatureParts($xSignature);

        if (ctype_alnum($resourceId)) {
            $resourceId = strtolower($resourceId);
        }

        $manifest = 'id:'.$resourceId
            .';request-id:'.$requestId
            .';ts:'.$timestamp
            .';';

        $expected = hash_hmac('sha256', $manifest, $secret);

        if (! hash_equals($expected, strtolower($signature))) {
            throw new DomainException(
                'La firma del webhook de Mercado Pago no es válida.'
            );
        }
    }

    /**
     * @return array{0:string,1:string}
     */
    private function signatureParts(string $value): array
    {
        $value = trim($value);

        if ($value === '' || strlen($value) > 512) {
            throw new DomainException(
                'Mercado Pago webhook no informa x-signature válida.'
            );
        }

        $timestamp = null;
        $signature = null;

        foreach (explode(',', $value) as $part) {
            $pair = explode('=', trim($part), 2);

            if (count($pair) !== 2) {
                throw new DomainException(
                    'Mercado Pago webhook informa x-signature malformada.'
                );
            }

            [$key, $item] = $pair;
            $key = trim($key);
            $item = trim($item);

            if ($key === 'ts') {
                if ($timestamp !== null) {
                    throw new DomainException(
                        'Mercado Pago webhook repite ts en x-signature.'
                    );
                }

                if (preg_match('/^[0-9]{10,16}$/D', $item) !== 1) {
                    throw new DomainException(
                        'Mercado Pago webhook informa ts inválido.'
                    );
                }

                $timestamp = $item;
            }

            if ($key === 'v1') {
                if ($signature !== null) {
                    throw new DomainException(
                        'Mercado Pago webhook repite v1 en x-signature.'
                    );
                }

                if (preg_match('/^[A-Fa-f0-9]{64}$/D', $item) !== 1) {
                    throw new DomainException(
                        'Mercado Pago webhook informa v1 inválida.'
                    );
                }

                $signature = $item;
            }
        }

        if ($timestamp === null || $signature === null) {
            throw new DomainException(
                'Mercado Pago webhook requiere ts y v1 en x-signature.'
            );
        }

        return [$timestamp, $signature];
    }
}
