<?php

namespace App\Domain\Finance;

use App\Adapters\Finance\MercadoPago\MercadoPagoConnectionSecrets;
use App\Adapters\Finance\MercadoPago\MercadoPagoPointOrdersClient;
use App\Adapters\Finance\MercadoPago\MercadoPagoPointSandboxOrdersClient;
use DomainException;
use Illuminate\Support\Str;

final class MercadoPagoPointRefundSandboxSmokeRunner
{
    private const VIRTUAL_SERIAL =
        'SBX0000001';

    private const MAX_POLL_ATTEMPTS = 12;

    public function __construct(
        private readonly MercadoPagoPointOrdersClient $orders,
        private readonly MercadoPagoPointSandboxOrdersClient $sandbox
    ) {
    }

    public function run(
        MercadoPagoConnectionSecrets $secrets,
        int $amountMinor = 100,
        string $poiType = 'NEWLAND_N950'
    ): MercadoPagoPointRefundSandboxSmokeResult {
        if ($secrets->liveMode) {
            throw new DomainException(
                'El smoke P8.4.3.6 rechaza credenciales live.'
            );
        }

        if (
            $amountMinor <= 0
            || $amountMinor > 10000
        ) {
            throw new DomainException(
                'El smoke sandbox admite importes entre 1 y 10000 minor units.'
            );
        }

        $terminalId =
            $this->terminalId(
                $poiType
            );

        $reference =
            'srcm-p8436-'
            .Str::lower(
                Str::random(12)
            );

        $createKey =
            (string) Str::uuid();

        $order =
            $this->orders->createOrder(
                accessToken:
                    $secrets->accessToken,
                terminalId:
                    $terminalId,
                externalReference:
                    $reference,
                amountMinor:
                    $amountMinor,
                idempotencyKey:
                    $createKey,
                description:
                    'SRCM P8.4.3.6 sandbox refund simulation'
            );

        $this->assertSandboxOrder(
            $order
        );

        $orderId =
            $this->requiredIdentifier(
                $order['id'] ?? null,
                'order id'
            );

        $this->sandbox->simulateProcessed(
            $secrets->accessToken,
            $orderId
        );

        $processed =
            $this->waitForOrderStatus(
                $secrets,
                $orderId,
                'processed'
            );

        $this->assertArgentinaOrder(
            $processed
        );

        $payment =
            $this->singlePayment(
                $processed
            );

        $paymentId =
            $this->requiredIdentifier(
                $payment['id'] ?? null,
                'payment transaction id'
            );

        $paidAmountMinor =
            $this->paymentAmountMinor(
                $payment
            );

        if (
            $paidAmountMinor
                !== $amountMinor
        ) {
            throw new DomainException(
                'El pago sandbox procesado no coincide con el importe creado.'
            );
        }

        $this->sandbox->simulateRefunded(
            $secrets->accessToken,
            $orderId
        );

        $refunded =
            $this->waitForOrderStatus(
                $secrets,
                $orderId,
                'refunded'
            );

        $this->assertArgentinaOrder(
            $refunded
        );

        return new MercadoPagoPointRefundSandboxSmokeResult(
            orderId:
                $orderId,
            paymentId:
                $paymentId,
            terminalId:
                $terminalId,
            amountMinor:
                $amountMinor,
            currencyCode:
                'ARS',
            orderStatus:
                'refunded'
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function waitForOrderStatus(
        MercadoPagoConnectionSecrets $secrets,
        string $orderId,
        string $expectedStatus
    ): array {
        for (
            $attempt = 1;
            $attempt <= self::MAX_POLL_ATTEMPTS;
            $attempt++
        ) {
            $order =
                $this->orders->getOrder(
                    $secrets->accessToken,
                    $orderId
                );

            $this->assertSandboxOrder(
                $order
            );

            if (
                strtolower(
                    trim(
                        (string)
                        ($order['status'] ?? '')
                    )
                ) === $expectedStatus
            ) {
                return $order;
            }

            if (
                $attempt
                    < self::MAX_POLL_ATTEMPTS
            ) {
                usleep(
                    1_000_000
                );
            }
        }

        throw new DomainException(
            'La order sandbox no alcanzó '
            .$expectedStatus
            .' dentro de la ventana segura.'
        );
    }

    /**
     * @param array<string,mixed> $order
     */
    private function assertSandboxOrder(
        array $order
    ): void {
        if (
            ($order['type'] ?? null)
                !== 'point'
            || ($order['live_mode'] ?? null)
                !== false
        ) {
            throw new DomainException(
                'El smoke abortó porque Mercado Pago no confirmó una Point Order sandbox.'
            );
        }
    }

    /**
     * @param array<string,mixed> $order
     */
    private function assertArgentinaOrder(
        array $order
    ): void {
        $country =
            strtoupper(
                trim(
                    (string)
                    ($order['country_code'] ?? '')
                )
            );

        if (
            ! in_array(
                $country,
                [
                    'AR',
                    'ARG',
                ],
                true
            )
        ) {
            throw new DomainException(
                'El smoke P8.4.3.6 sólo valida Point Argentina / ARS.'
            );
        }
    }

    /**
     * @param array<string,mixed> $order
     * @return array<string,mixed>
     */
    private function singlePayment(
        array $order
    ): array {
        $payments =
            $order['transactions']['payments']
            ?? null;

        if (
            ! is_array($payments)
            || count($payments) !== 1
            || ! is_array($payments[0])
        ) {
            throw new DomainException(
                'La order sandbox no posee un pago único.'
            );
        }

        return $payments[0];
    }

    /**
     * @param array<string,mixed> $payment
     */
    private function paymentAmountMinor(
        array $payment
    ): int {
        foreach (
            [
                'paid_amount',
                'amount',
            ] as $field
        ) {
            if (
                array_key_exists(
                    $field,
                    $payment
                )
                && $payment[$field] !== null
                && $payment[$field] !== ''
            ) {
                return $this->moneyToMinor(
                    $payment[$field]
                );
            }
        }

        throw new DomainException(
            'El pago sandbox no informa importe.'
        );
    }

    private function moneyToMinor(
        mixed $value
    ): int {
        if (is_float($value)) {
            throw new DomainException(
                'El smoke sandbox rechaza dinero float.'
            );
        }

        if (
            ! is_string($value)
            && ! is_int($value)
        ) {
            throw new DomainException(
                'El smoke sandbox recibió dinero inválido.'
            );
        }

        $text =
            trim(
                (string) $value
            );

        if (
            preg_match(
                '/^(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?$/D',
                $text,
                $match
            ) !== 1
        ) {
            throw new DomainException(
                'El smoke sandbox recibió un decimal no seguro.'
            );
        }

        $minorText =
            ltrim(
                $match[1]
                .str_pad(
                    $match[2] ?? '',
                    2,
                    '0'
                ),
                '0'
            );

        $minorText =
            $minorText === ''
                ? '0'
                : $minorText;

        if (
            strlen($minorText) > 18
        ) {
            throw new DomainException(
                'El smoke sandbox recibió dinero fuera de rango.'
            );
        }

        $minor =
            (int) $minorText;

        if (
            $minor !== 0
            && (string) $minor
                !== $minorText
        ) {
            throw new DomainException(
                'El smoke sandbox recibió dinero fuera del entero admitido.'
            );
        }

        return $minor;
    }

    private function requiredIdentifier(
        mixed $value,
        string $label
    ): string {
        if (
            ! is_string($value)
            && ! is_int($value)
        ) {
            throw new DomainException(
                'Mercado Pago '.$label
                .' no es válido.'
            );
        }

        $identifier =
            trim(
                (string) $value
            );

        if (
            $identifier === ''
            || strlen($identifier) > 191
            || preg_match(
                '/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D',
                $identifier
            ) !== 1
        ) {
            throw new DomainException(
                'Mercado Pago '.$label
                .' no es válido.'
            );
        }

        return $identifier;
    }

    private function terminalId(
        string $poiType
    ): string {
        $poiType =
            trim($poiType);

        if (
            preg_match(
                '/^[A-Za-z0-9_-]{1,100}$/D',
                $poiType
            ) !== 1
        ) {
            throw new DomainException(
                'El poi_type sandbox no es válido.'
            );
        }

        return $poiType
            .'__'
            .self::VIRTUAL_SERIAL;
    }
}
