<?php

namespace App\Adapters\Finance\MercadoPago;

use DomainException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class MercadoPagoPointSandboxOrdersClient
{
    private const BASE_URL =
        'https://api.mercadopago.com';

    public function simulateProcessed(
        string $accessToken,
        string $orderId
    ): void {
        $this->simulateStatus(
            $accessToken,
            $orderId,
            'processed'
        );
    }

    public function simulateRefunded(
        string $accessToken,
        string $orderId
    ): void {
        $this->simulateStatus(
            $accessToken,
            $orderId,
            'refunded'
        );
    }

    private function simulateStatus(
        string $accessToken,
        string $orderId,
        string $status
    ): void {
        $token =
            $this->accessToken(
                $accessToken
            );

        $id =
            $this->orderId(
                $orderId
            );

        if (
            ! in_array(
                $status,
                [
                    'processed',
                    'refunded',
                ],
                true
            )
        ) {
            throw new DomainException(
                'El estado sandbox solicitado no está permitido.'
            );
        }

        $response =
            Http::acceptJson()
                ->asJson()
                ->withToken($token)
                ->timeout(20)
                ->post(
                    self::BASE_URL
                    .'/v1/orders/'
                    .rawurlencode($id)
                    .'/events',
                    [
                        'status' =>
                            $status,
                    ]
                );

        if ($response->status() !== 204) {
            throw new DomainException(
                'Mercado Pago sandbox '
                .$status
                .' simulation falló '
                .$this->safeFailure(
                    $response
                )
                .'.'
            );
        }
    }

    private function accessToken(
        string $value
    ): string {
        $value =
            trim($value);

        if (
            $value === ''
            || strlen($value) > 1024
        ) {
            throw new DomainException(
                'La credencial transitoria sandbox de Mercado Pago no es válida.'
            );
        }

        return $value;
    }

    private function orderId(
        string $value
    ): string {
        $value =
            trim($value);

        if (
            $value === ''
            || strlen($value) > 191
            || preg_match(
                '/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D',
                $value
            ) !== 1
        ) {
            throw new DomainException(
                'El ID sandbox de la order Mercado Pago no es válido.'
            );
        }

        return $value;
    }

    private function safeFailure(
        Response $response
    ): string {
        $parts = [
            'HTTP '.$response->status(),
        ];

        $requestId =
            trim(
                (string)
                $response->header(
                    'x-request-id'
                )
            );

        if (
            $requestId !== ''
            && preg_match(
                '/^[A-Za-z0-9._:-]{1,128}$/D',
                $requestId
            ) === 1
        ) {
            $parts[] =
                'request_id='
                .$requestId;
        }

        return '('
            .implode(
                '; ',
                $parts
            )
            .')';
    }
}
