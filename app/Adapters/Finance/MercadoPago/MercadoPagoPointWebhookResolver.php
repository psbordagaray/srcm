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
     * P5.4 intentionally receives credentials and expected external identity
     * as transient arguments. It neither persists them nor selects tenancy
     * from unverified webhook body data.
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

        $order = $this->ordersClient->getOrder(
            $accessToken,
            $notification->resourceId
        );

        if (
            ! is_string($order['id'] ?? null)
            || trim($order['id']) !== $notification->resourceId
        ) {
            throw new DomainException(
                'Mercado Pago devolvió una order distinta a la notificada.'
            );
        }

        $observation = $this->adapter->normalize($order);

        if (
            $observation->externalOperationId
                !== $notification->resourceId
        ) {
            throw new DomainException(
                'La observación Mercado Pago no conserva la order notificada.'
            );
        }

        return $observation;
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
