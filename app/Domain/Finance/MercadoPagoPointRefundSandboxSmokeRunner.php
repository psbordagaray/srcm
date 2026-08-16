<?php

namespace App\Domain\Finance;

use App\Adapters\Finance\MercadoPago\MercadoPagoConnectionSecrets;
use App\Adapters\Finance\MercadoPago\MercadoPagoPointOrdersClient;
use App\Adapters\Finance\MercadoPago\MercadoPagoPointRefundAdapter;
use App\Adapters\Finance\MercadoPago\MercadoPagoPointSandboxOrdersClient;
use App\Enums\FinancialMovementStatus;
use DomainException;
use Illuminate\Support\Str;

final class MercadoPagoPointRefundSandboxSmokeRunner
{
    private const VIRTUAL_SERIAL =
        'SBX0000001';

    private const MAX_POLL_ATTEMPTS = 12;

    public function __construct(
        private readonly MercadoPagoPointOrdersClient $orders,
        private readonly MercadoPagoPointSandboxOrdersClient $sandbox,
        private readonly MercadoPagoPointRefundAdapter $refunds
    ) {
    }

    public function run(
        MercadoPagoConnectionSecrets $secrets,
        int $amountMinor = 100,
        string $poiType = 'NEWLAND_N950'
    ): MercadoPagoPointRefundSandboxSmokeResult {
        if ($secrets->liveMode) {
            throw new DomainException(
                'El smoke P8.4.3.5 rechaza credenciales live.'
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
            'srcm-p8435-'
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
                    'SRCM P8.4.3.5 sandbox refund smoke'
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
            $this->waitForProcessedOrder(
                $secrets,
                $orderId
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

        $refundKey =
            'srcm-sandbox-refund:'
            .Str::uuid();

        $refundOrder =
            $this->orders->refundOrder(
                accessToken:
                    $secrets->accessToken,
                orderId:
                    $orderId,
                paymentTransactionId:
                    $paymentId,
                amountMinor:
                    $amountMinor,
                idempotencyKey:
                    $refundKey,
                total:
                    true
            );

        $refund =
            $this->singleMatchingRefund(
                $refundOrder,
                $paymentId,
                $amountMinor
            );

        $refundId =
            $this->requiredIdentifier(
                $refund['id'] ?? null,
                'refund id'
            );

        $observation =
            $this->refunds
                ->normalizeObservedRefund(
                    $refundOrder,
                    $refundId,
                    $amountMinor,
                    'ARS'
                );

        if (
            $observation->status
                === FinancialMovementStatus::Pending
        ) {
            $observation =
                $this->waitForPostedRefund(
                    $secrets,
                    $orderId,
                    $refundId,
                    $amountMinor
                );
        }

        if (
            $observation->status
                !== FinancialMovementStatus::Posted
        ) {
            throw new DomainException(
                'El smoke sandbox no obtuvo evidencia final Posted del refund.'
            );
        }

        return new MercadoPagoPointRefundSandboxSmokeResult(
            orderId:
                $orderId,
            refundId:
                $refundId,
            terminalId:
                $terminalId,
            amountMinor:
                $amountMinor,
            currencyCode:
                'ARS',
            status:
                $observation->status
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function waitForProcessedOrder(
        MercadoPagoConnectionSecrets $secrets,
        string $orderId
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
                ) === 'processed'
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
            'La order sandbox no alcanzó processed dentro de la ventana segura.'
        );
    }

    private function waitForPostedRefund(
        MercadoPagoConnectionSecrets $secrets,
        string $orderId,
        string $refundId,
        int $amountMinor
    ) {
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

            $this->assertArgentinaOrder(
                $order
            );

            try {
                $observation =
                    $this->refunds
                        ->normalizeObservedRefund(
                            $order,
                            $refundId,
                            $amountMinor,
                            'ARS'
                        );

                if (
                    $observation->status
                        === FinancialMovementStatus::Posted
                ) {
                    return $observation;
                }
            } catch (DomainException) {
                // La propagación puede tardar algunos segundos en sandbox.
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
            'El refund sandbox no alcanzó Posted dentro de la ventana segura.'
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
                'El smoke P8.4.3.5 sólo valida Point Argentina / ARS.'
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
     * @param array<string,mixed> $order
     * @return array<string,mixed>
     */
    private function singleMatchingRefund(
        array $order,
        string $paymentId,
        int $amountMinor
    ): array {
        $refunds =
            $order['transactions']['refunds']
            ?? null;

        if (! is_array($refunds)) {
            throw new DomainException(
                'La respuesta sandbox no incluye refunds.'
            );
        }

        $matches = [];

        foreach ($refunds as $refund) {
            if (! is_array($refund)) {
                continue;
            }

            if (
                $this->identifierOrNull(
                    $refund['transaction_id'] ?? null
                ) === $paymentId
                && $this->moneyToMinor(
                    $refund['amount'] ?? null
                ) === $amountMinor
            ) {
                $matches[] =
                    $refund;
            }
        }

        if (count($matches) !== 1) {
            throw new DomainException(
                'La respuesta sandbox no identifica un refund único para el pago creado.'
            );
        }

        return $matches[0];
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

    private function identifierOrNull(
        mixed $value
    ): ?string {
        try {
            return $this->requiredIdentifier(
                $value,
                'provider id'
            );
        } catch (DomainException) {
            return null;
        }
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
