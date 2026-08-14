<?php

namespace App\Adapters\Finance\MercadoPago;

use App\Domain\Finance\ExternalFinancialProviderObservation;
use DomainException;

final class MercadoPagoPointWebhookResolver
{
    public function __construct(
        private readonly MercadoPagoWebhookSignatureVerifier $signatureVerifier,
        private readonly MercadoPagoPointOrdersClient $ordersClient,
        private readonly MercadoPagoExternalFinancialProviderAdapter $adapter
    ) {
    }

    /**
     * P5.5 splits cheap synchronous authentication from remote resolution so
     * the public endpoint can ACK immediately after safely enqueueing work.
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     */
    public function authenticate(
        string $xSignature,
        string $xRequestId,
        array $query,
        array $body,
        string $webhookSecret,
        string $expectedApplicationId,
        string $expectedUserId,
        bool $expectedLiveMode
    ): MercadoPagoPointWebhookNotification {
        $queryDataId = $query['data.id'] ?? null;

        if (! is_string($queryDataId) && ! is_int($queryDataId)) {
            throw new DomainException(
                'Mercado Pago webhook requiere data.id verificable.'
            );
        }

        $resourceId = trim((string) $queryDataId);

        $this->signatureVerifier->verify(
            $xSignature,
            $xRequestId,
            $resourceId,
            $webhookSecret
        );

        $notification = MercadoPagoPointWebhookNotification::fromRequest(
            $query,
            $body
        );

        if (
            $notification->applicationId
                !== $this->numericExpected(
                    $expectedApplicationId,
                    'application_id esperado'
                )
        ) {
            throw new DomainException(
                'Mercado Pago webhook no pertenece a la aplicación esperada.'
            );
        }

        if (
            $notification->userId
                !== $this->numericExpected(
                    $expectedUserId,
                    'user_id esperado'
                )
        ) {
            throw new DomainException(
                'Mercado Pago webhook no pertenece al usuario esperado.'
            );
        }

        if ($notification->liveMode !== $expectedLiveMode) {
            throw new DomainException(
                'Mercado Pago webhook no pertenece al modo esperado.'
            );
        }

        return $notification;
    }

    public function resolveResource(
        string $resourceId,
        string $accessToken
    ): ExternalFinancialProviderObservation {
        $resourceId = trim($resourceId);

        if (
            $resourceId === ''
            || strlen($resourceId) > 191
            || preg_match(
                '/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D',
                $resourceId
            ) !== 1
        ) {
            throw new DomainException(
                'Mercado Pago webhook requiere un recurso válido.'
            );
        }

        $order = $this->ordersClient->getOrder(
            $accessToken,
            $resourceId
        );

        if (
            ! is_string($order['id'] ?? null)
            || trim($order['id']) !== $resourceId
        ) {
            throw new DomainException(
                'Mercado Pago devolvió una order distinta a la notificada.'
            );
        }

        $observation = $this->adapter->normalize($order);

        if (
            $observation->externalOperationId
                !== $resourceId
        ) {
            throw new DomainException(
                'La observación Mercado Pago no conserva la order notificada.'
            );
        }

        return $observation;
    }

    /**
     * Backwards-compatible composition retained for P5.4 callers/tests.
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     */
    public function resolve(
        string $xSignature,
        string $xRequestId,
        array $query,
        array $body,
        string $webhookSecret,
        string $accessToken,
        string $expectedApplicationId,
        string $expectedUserId,
        bool $expectedLiveMode
    ): ExternalFinancialProviderObservation {
        $notification = $this->authenticate(
            $xSignature,
            $xRequestId,
            $query,
            $body,
            $webhookSecret,
            $expectedApplicationId,
            $expectedUserId,
            $expectedLiveMode
        );

        return $this->resolveResource(
            $notification->resourceId,
            $accessToken
        );
    }

    private function numericExpected(
        string $value,
        string $label
    ): string {
        $value = trim($value);

        if (preg_match('/^[0-9]{1,30}$/D', $value) !== 1) {
            throw new DomainException(
                'Mercado Pago '.$label.' no es válido.'
            );
        }

        return $value;
    }
}
