<?php

namespace App\Adapters\Finance\MercadoPago;

use DateTimeImmutable;
use DateTimeInterface;
use DomainException;

final readonly class MercadoPagoPointWebhookNotification
{
    public function __construct(
        public string $resourceId,
        public string $action,
        public string $applicationId,
        public string $userId,
        public bool $liveMode,
        public ?string $notificationId,
        public ?DateTimeInterface $createdAt
    ) {
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     */
    public static function fromRequest(
        array $query,
        array $body
    ): self {
        if (($query['type'] ?? null) !== 'order') {
            throw new DomainException(
                'Mercado Pago Point webhook requiere type=order en query.'
            );
        }

        if (($body['type'] ?? null) !== 'order') {
            throw new DomainException(
                'Mercado Pago Point webhook requiere type=order en body.'
            );
        }

        $queryId = self::identifier(
            $query['data.id'] ?? null,
            'query data.id'
        );

        $bodyData = $body['data'] ?? null;

        if (! is_array($bodyData)) {
            throw new DomainException(
                'Mercado Pago Point webhook no contiene data válida.'
            );
        }

        $bodyId = self::identifier(
            $bodyData['id'] ?? null,
            'body data.id'
        );

        if ($queryId !== $bodyId) {
            throw new DomainException(
                'Mercado Pago Point webhook no coincide entre query y body.'
            );
        }

        $action = self::action($body['action'] ?? null);
        $applicationId = self::numericIdentifier(
            $body['application_id'] ?? null,
            'application_id'
        );
        $userId = self::numericIdentifier(
            $body['user_id'] ?? null,
            'user_id'
        );
        $notificationId = self::optionalIdentifier(
            $body['id'] ?? null,
            'notification id'
        );

        if (! is_bool($body['live_mode'] ?? null)) {
            throw new DomainException(
                'Mercado Pago Point webhook no informa live_mode booleano.'
            );
        }

        return new self(
            resourceId: $queryId,
            action: $action,
            applicationId: $applicationId,
            userId: $userId,
            liveMode: $body['live_mode'],
            notificationId: $notificationId,
            createdAt: self::date($body['date_created'] ?? null)
        );
    }

    private static function optionalIdentifier(
        mixed $value,
        string $label
    ): ?string {
        if ($value === null || $value === '') {
            return null;
        }

        return self::identifier($value, $label);
    }

    private static function action(mixed $value): string
    {
        if (! is_string($value)) {
            throw new DomainException(
                'Mercado Pago Point webhook no informa action válida.'
            );
        }

        $value = trim($value);

        $allowed = [
            'order.processed',
            'order.canceled',
            'order.refunded',
            'order.action_required',
            'order.failed',
            'order.expired',
        ];

        if (! in_array($value, $allowed, true)) {
            throw new DomainException(
                'Mercado Pago Point webhook informa una action no admitida.'
            );
        }

        return $value;
    }

    private static function identifier(
        mixed $value,
        string $label
    ): string {
        if (! is_string($value) && ! is_int($value)) {
            throw new DomainException(
                'Mercado Pago Point webhook no informa '.$label.' válido.'
            );
        }

        $value = trim((string) $value);

        if (
            $value === ''
            || strlen($value) > 191
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $value) !== 1
        ) {
            throw new DomainException(
                'Mercado Pago Point webhook no informa '.$label.' válido.'
            );
        }

        return $value;
    }

    private static function numericIdentifier(
        mixed $value,
        string $label
    ): string {
        if (! is_string($value) && ! is_int($value)) {
            throw new DomainException(
                'Mercado Pago Point webhook no informa '.$label.' válido.'
            );
        }

        $value = trim((string) $value);

        if (preg_match('/^[0-9]{1,30}$/D', $value) !== 1) {
            throw new DomainException(
                'Mercado Pago Point webhook no informa '.$label.' válido.'
            );
        }

        return $value;
    }

    private static function date(mixed $value): ?DateTimeInterface
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new DomainException(
                'Mercado Pago Point webhook informa date_created inválida.'
            );
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable) {
            throw new DomainException(
                'Mercado Pago Point webhook informa date_created inválida.'
            );
        }
    }
}
