<?php

namespace App\Adapters\Finance\MercadoPago;

use App\Contracts\Finance\ExternalFinancialProviderAdapter;
use App\Domain\Finance\ExternalFinancialProviderObservation;
use App\Enums\FinancialMovementDirection;
use App\Enums\FinancialMovementStatus;
use DateTimeImmutable;
use DateTimeInterface;
use DomainException;

final class MercadoPagoExternalFinancialProviderAdapter implements ExternalFinancialProviderAdapter
{
    public function providerKey(): string
    {
        return 'mercado-pago';
    }

    /**
     * Normalize a full Mercado Pago Point Order resource (API response or a
     * webhook envelope that embeds the full order under data).
     *
     * Notification-only envelopes are intentionally rejected: a webhook ID is
     * not financial evidence by itself. The caller must fetch/obtain the full
     * resource before normalization.
     *
     * @param array<string, mixed> $payload
     */
    public function normalize(array $payload): ExternalFinancialProviderObservation
    {
        $order = $this->unwrapOrder($payload);

        if (($order['type'] ?? null) !== 'point') {
            throw new DomainException(
                'Mercado Pago P5.2 sólo normaliza recursos completos de Point Orders.'
            );
        }

        $orderId = $this->requiredIdentifier($order['id'] ?? null, 'order id');
        $providerStatus = strtolower(trim((string) ($order['status'] ?? '')));

        if ($providerStatus === '') {
            throw new DomainException('La order de Mercado Pago no informa status.');
        }

        $status = $this->mapStatus($providerStatus);
        $currency = strtoupper(trim((string) ($order['currency'] ?? '')));

        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new DomainException('La order de Mercado Pago no informa una moneda ISO válida.');
        }

        $grossAmountMinor = $this->resolveGrossAmountMinor($order);
        $version = $this->safeVersion($order['version'] ?? null);
        $observationKey = 'point-order:'.$orderId.':'.$providerStatus;

        if ($version !== null) {
            $observationKey .= ':v'.$version;
        }

        $statusDetail = trim((string) ($order['status_detail'] ?? ''));
        $rawReference = 'Mercado Pago Point order '.$orderId.' / '.$providerStatus;

        if ($statusDetail !== '') {
            $rawReference .= ' / '.$this->safeReferenceFragment($statusDetail);
        }

        return new ExternalFinancialProviderObservation(
            providerKey: $this->providerKey(),
            observationKey: $observationKey,
            externalOperationId: $orderId,
            direction: FinancialMovementDirection::Credit,
            status: $status,
            currencyCode: $currency,
            grossAmountMinor: $grossAmountMinor,
            netAmountMinor: $grossAmountMinor,
            feeAmountMinor: 0,
            withholdingAmountMinor: 0,
            rawReference: $rawReference,
            occurredAt: $this->occurredAt($order, $payload)
        );
    }

    /** @param array<string, mixed> $payload */
    private function unwrapOrder(array $payload): array
    {
        if (($payload['type'] ?? null) === 'point' && isset($payload['id'])) {
            return $payload;
        }

        $data = $payload['data'] ?? null;

        if (is_array($data) && ($data['type'] ?? null) === 'point' && isset($data['id'])) {
            return $data;
        }

        throw new DomainException(
            'La notificación no contiene un recurso completo de Mercado Pago Point; debe resolverse antes de ingerir.'
        );
    }

    private function mapStatus(string $status): FinancialMovementStatus
    {
        return match ($status) {
            'created', 'at_terminal', 'action_required' => FinancialMovementStatus::Pending,
            'processed' => FinancialMovementStatus::Posted,
            'refunded' => FinancialMovementStatus::Reversed,
            'canceled', 'cancelled', 'expired', 'failed' => FinancialMovementStatus::Failed,
            default => throw new DomainException(
                'Status de Mercado Pago Point no reconocido: '.$status.'.'
            ),
        };
    }

    /** @param array<string, mixed> $order */
    private function resolveGrossAmountMinor(array $order): int
    {
        foreach (['total_paid_amount', 'total_amount'] as $field) {
            if (array_key_exists($field, $order) && $order[$field] !== null && $order[$field] !== '') {
                return $this->moneyToMinor($order[$field], $field);
            }
        }

        $payments = $order['transactions']['payments'] ?? null;

        if (is_array($payments) && isset($payments[0]) && is_array($payments[0])) {
            foreach (['paid_amount', 'amount'] as $field) {
                if (
                    array_key_exists($field, $payments[0])
                    && $payments[0][$field] !== null
                    && $payments[0][$field] !== ''
                ) {
                    return $this->moneyToMinor($payments[0][$field], 'transactions.payments[0].'.$field);
                }
            }
        }

        throw new DomainException('La order de Mercado Pago no contiene un importe financiero normalizable.');
    }

    private function moneyToMinor(mixed $value, string $field): int
    {
        if (is_float($value)) {
            throw new DomainException(
                'Mercado Pago '.$field.' llegó como float; se rechaza para evitar redondeo binario ambiguo.'
            );
        }

        if (! is_string($value) && ! is_int($value)) {
            throw new DomainException('Mercado Pago '.$field.' no tiene formato monetario válido.');
        }

        $text = trim((string) $value);

        if (preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?$/D', $text, $match) !== 1) {
            throw new DomainException('Mercado Pago '.$field.' no tiene formato decimal seguro.');
        }

        $units = $match[1];
        $decimals = str_pad($match[2] ?? '', 2, '0');
        $minorText = ltrim($units.$decimals, '0');
        $minorText = $minorText === '' ? '0' : $minorText;

        if (strlen($minorText) > 18) {
            throw new DomainException('Mercado Pago '.$field.' excede el rango monetario admitido.');
        }

        $minor = (int) $minorText;

        if ((string) $minor !== $minorText && $minor !== 0) {
            throw new DomainException('Mercado Pago '.$field.' excede el entero admitido por la plataforma.');
        }

        return $minor;
    }

    private function requiredIdentifier(mixed $value, string $label): string
    {
        if (! is_string($value) && ! is_int($value)) {
            throw new DomainException('Mercado Pago '.$label.' no es válido.');
        }

        $identifier = trim((string) $value);

        if (
            $identifier === ''
            || strlen($identifier) > 191
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $identifier) !== 1
        ) {
            throw new DomainException('Mercado Pago '.$label.' no es válido.');
        }

        return $identifier;
    }

    private function safeVersion(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) && ! is_int($value)) {
            throw new DomainException('La versión de la order de Mercado Pago no es válida.');
        }

        $version = trim((string) $value);

        if (preg_match('/^[A-Za-z0-9._-]{1,32}$/D', $version) !== 1) {
            throw new DomainException('La versión de la order de Mercado Pago no es válida.');
        }

        return $version;
    }

    private function safeReferenceFragment(string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9 _.-]+/u', '', $value) ?? '';
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return substr($value, 0, 80);
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $envelope
     */
    private function occurredAt(array $order, array $envelope): ?DateTimeInterface
    {
        foreach (
            [
                $order['last_updated_date'] ?? null,
                $order['date_last_updated'] ?? null,
                $order['updated_date'] ?? null,
                $order['created_date'] ?? null,
                $order['date_created'] ?? null,
                $envelope['date_created'] ?? null,
            ] as $value
        ) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            try {
                return new DateTimeImmutable($value);
            } catch (\Throwable) {
                throw new DomainException('Mercado Pago informó un timestamp externo inválido.');
            }
        }

        return null;
    }
}
