<?php

namespace App\Adapters\Finance\MercadoPago;

use App\Contracts\Finance\FinancialProviderRefundAdapter;
use App\Contracts\Finance\MercadoPagoConnectionSecretStore;
use App\Domain\Finance\ExternalFinancialProviderObservation;
use App\Domain\Finance\FinancialProviderRefundRequest;
use App\Enums\FinancialMovementDirection;
use App\Enums\FinancialMovementStatus;
use App\Models\FinancialProviderConnection;
use DomainException;

final class MercadoPagoPointRefundAdapter
    implements FinancialProviderRefundAdapter
{
    public function __construct(
        private readonly MercadoPagoConnectionSecretStore $secrets,
        private readonly MercadoPagoPointOrdersClient $orders
    ) {
    }

    public function providerKey(): string
    {
        return 'mercado-pago';
    }

    public function submitRefund(
        FinancialProviderConnection $connection,
        FinancialProviderRefundRequest $request
    ): ExternalFinancialProviderObservation {
        if (
            $connection->provider_key
                !== $this->providerKey()
            || ! $connection->active
        ) {
            throw new DomainException(
                'La conexión Mercado Pago no está disponible para reembolso.'
            );
        }

        $secret =
            $this->secrets->forConnection(
                $connection
            );

        $order =
            $this->orders->getOrder(
                $secret->accessToken,
                $request->originalExternalOperationId
            );

        $payment =
            $this->singlePaymentTransaction(
                $order
            );

        $paymentId =
            $this->requiredIdentifier(
                $payment['id'] ?? null,
                'payment transaction id'
            );

        if (
            $this->orderCurrency($order)
                !== strtoupper(
                    trim(
                        $request->currencyCode
                    )
                )
        ) {
            throw new DomainException(
                'La moneda de la Point Order no coincide con el reembolso instruido.'
            );
        }

        $paidAmountMinor =
            $this->paymentAmountMinor(
                $payment
            );

        if (
            $request->amountMinor <= 0
            || $request->amountMinor
                > $paidAmountMinor
        ) {
            throw new DomainException(
                'El importe del reembolso Mercado Pago excede el pago original observado.'
            );
        }

        $total =
            $request->amountMinor
                === $paidAmountMinor;

        $response =
            $this->orders->refundOrder(
                accessToken:
                    $secret->accessToken,
                orderId:
                    $request
                        ->originalExternalOperationId,
                paymentTransactionId:
                    $paymentId,
                amountMinor:
                    $request->amountMinor,
                idempotencyKey:
                    $request
                        ->providerIdempotencyKey,
                total:
                    $total
            );

        return $this->normalizeRefund(
            $response,
            $paymentId,
            $request
        );
    }

    /**
     * Normalize one full Point Order resource after API/webhook/polling and
     * return the unique refund entry matching the expected refund identity.
     *
     * @param array<string,mixed> $order
     */
    public function normalizeObservedRefund(
        array $order,
        string $refundId,
        int $amountMinor,
        string $currencyCode
    ): ExternalFinancialProviderObservation {
        $refundId =
            $this->requiredIdentifier(
                $refundId,
                'refund id'
            );

        $matches = [];

        foreach (
            $this->refundTransactions(
                $order
            ) as $refund
        ) {
            if (
                $this->requiredIdentifier(
                    $refund['id'] ?? null,
                    'refund id'
                ) === $refundId
            ) {
                $matches[] = $refund;
            }
        }

        if (count($matches) !== 1) {
            throw new DomainException(
                'La order Mercado Pago no identifica de forma inequívoca el reembolso esperado.'
            );
        }

        return $this->refundObservation(
            $matches[0],
            $amountMinor,
            $currencyCode
        );
    }

    /**
     * @param array<string,mixed> $response
     */
    private function normalizeRefund(
        array $response,
        string $paymentId,
        FinancialProviderRefundRequest $request
    ): ExternalFinancialProviderObservation {
        $matches = [];

        foreach (
            $this->refundTransactions(
                $response
            ) as $refund
        ) {
            $transactionId =
                $this->requiredIdentifier(
                    $refund['transaction_id'] ?? null,
                    'refund transaction_id'
                );

            $amountMinor =
                $this->moneyToMinor(
                    $refund['amount'] ?? null,
                    'transactions.refunds[].amount'
                );

            if (
                $transactionId === $paymentId
                && $amountMinor
                    === $request->amountMinor
            ) {
                $matches[] = $refund;
            }
        }

        if (count($matches) !== 1) {
            throw new DomainException(
                'Mercado Pago no devolvió una identidad única para el reembolso solicitado.'
            );
        }

        return $this->refundObservation(
            $matches[0],
            $request->amountMinor,
            $request->currencyCode
        );
    }

    /**
     * @param array<string,mixed> $refund
     */
    private function refundObservation(
        array $refund,
        int $amountMinor,
        string $currencyCode
    ): ExternalFinancialProviderObservation {
        $refundId =
            $this->requiredIdentifier(
                $refund['id'] ?? null,
                'refund id'
            );

        $observedAmountMinor =
            $this->moneyToMinor(
                $refund['amount'] ?? null,
                'transactions.refunds[].amount'
            );

        if (
            $observedAmountMinor
                !== $amountMinor
        ) {
            throw new DomainException(
                'El importe del refund Mercado Pago no coincide con la instrucción.'
            );
        }

        $statusText =
            strtolower(
                trim(
                    (string)
                    ($refund['status'] ?? '')
                )
            );

        $status =
            match ($statusText) {
                'processing' =>
                    FinancialMovementStatus::Pending,
                'processed' =>
                    FinancialMovementStatus::Posted,
                default =>
                    throw new DomainException(
                        'Status de refund Mercado Pago no reconocido: '
                        .$statusText.'.'
                    ),
            };

        $currencyCode =
            strtoupper(
                trim($currencyCode)
            );

        if (
            preg_match(
                '/^[A-Z]{3}$/D',
                $currencyCode
            ) !== 1
        ) {
            throw new DomainException(
                'La moneda del refund Mercado Pago no es válida.'
            );
        }

        return new ExternalFinancialProviderObservation(
            providerKey:
                $this->providerKey(),
            observationKey:
                'point-refund:'
                .$refundId.':'
                .$statusText,
            externalOperationId:
                $refundId,
            direction:
                FinancialMovementDirection::Debit,
            status:
                $status,
            currencyCode:
                $currencyCode,
            grossAmountMinor:
                $amountMinor,
            netAmountMinor:
                $amountMinor,
            feeAmountMinor:
                0,
            withholdingAmountMinor:
                0,
            rawReference:
                'Mercado Pago Point refund '
                .$refundId.' / '
                .$statusText,
            occurredAt:
                null
        );
    }

    /**
     * @param array<string,mixed> $order
     * @return array<string,mixed>
     */
    private function singlePaymentTransaction(
        array $order
    ): array {
        if (
            ($order['type'] ?? null)
                !== 'point'
        ) {
            throw new DomainException(
                'El reembolso SRCM sólo admite una Point Order completa.'
            );
        }

        $status =
            strtolower(
                trim(
                    (string)
                    ($order['status'] ?? '')
                )
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
                'La Point Order no se encuentra en un estado reembolsable o recuperable.'
            );
        }

        $payments =
            $order['transactions']['payments']
            ?? null;

        if (
            ! is_array($payments)
            || count($payments) !== 1
            || ! is_array($payments[0])
        ) {
            throw new DomainException(
                'La Point Order no posee una transacción de pago única y explícita.'
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
        if (
            ! array_key_exists(
                'paid_amount',
                $payment
            )
            && array_key_exists(
                'tip_amount',
                $payment
            )
            && $payment['tip_amount'] !== null
            && $payment['tip_amount'] !== ''
            && $this->moneyToMinor(
                $payment['tip_amount'],
                'transactions.payments[0].tip_amount'
            ) > 0
        ) {
            throw new DomainException(
                'La Point Order informa propina pero no paid_amount; no se puede decidir total/parcial con seguridad.'
            );
        }

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
                && $payment[$field]
                    !== null
                && $payment[$field]
                    !== ''
            ) {
                return $this->moneyToMinor(
                    $payment[$field],
                    'transactions.payments[0].'
                    .$field
                );
            }
        }

        throw new DomainException(
            'La Point Order no informa el importe del pago original.'
        );
    }

    /**
     * @param array<string,mixed> $order
     */
    private function orderCurrency(
        array $order
    ): string {
        $explicit =
            strtoupper(
                trim(
                    (string)
                    ($order['currency'] ?? '')
                )
            );

        if ($explicit !== '') {
            if (
                preg_match(
                    '/^[A-Z]{3}$/D',
                    $explicit
                ) !== 1
            ) {
                throw new DomainException(
                    'La Point Order informa una moneda ISO inválida.'
                );
            }

            return $explicit;
        }

        $country =
            strtoupper(
                trim(
                    (string)
                    ($order['country_code'] ?? '')
                )
            );

        return match ($country) {
            'AR', 'ARG' =>
                'ARS',
            default =>
                throw new DomainException(
                    'La Point Order no informa una moneda verificable.'
                ),
        };
    }

    /**
     * @param array<string,mixed> $order
     * @return list<array<string,mixed>>
     */
    private function refundTransactions(
        array $order
    ): array {
        $refunds =
            $order['transactions']['refunds']
            ?? null;

        if (! is_array($refunds)) {
            throw new DomainException(
                'Mercado Pago no devolvió transacciones de refund.'
            );
        }

        $normalized = [];

        foreach ($refunds as $refund) {
            if (! is_array($refund)) {
                throw new DomainException(
                    'Mercado Pago devolvió una transacción de refund inválida.'
                );
            }

            $normalized[] = $refund;
        }

        if ($normalized === []) {
            throw new DomainException(
                'Mercado Pago no devolvió evidencia de refund.'
            );
        }

        return $normalized;
    }

    private function moneyToMinor(
        mixed $value,
        string $field
    ): int {
        if (is_float($value)) {
            throw new DomainException(
                'Mercado Pago '.$field
                .' llegó como float; se rechaza para evitar redondeo ambiguo.'
            );
        }

        if (
            ! is_string($value)
            && ! is_int($value)
        ) {
            throw new DomainException(
                'Mercado Pago '.$field
                .' no tiene formato monetario válido.'
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
                'Mercado Pago '.$field
                .' no tiene formato decimal seguro.'
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

        if (strlen($minorText) > 18) {
            throw new DomainException(
                'Mercado Pago '.$field
                .' excede el rango monetario admitido.'
            );
        }

        $minor =
            (int) $minorText;

        if (
            (string) $minor
                !== $minorText
            && $minor !== 0
        ) {
            throw new DomainException(
                'Mercado Pago '.$field
                .' excede el entero admitido.'
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
}
