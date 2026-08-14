<?php

namespace App\Domain\Finance;

use App\Adapters\Finance\MercadoPago\MercadoPagoPointWebhookReceipt;
use App\Adapters\Finance\MercadoPago\MercadoPagoPointWebhookResolver;
use App\Adapters\Finance\MercadoPago\MercadoPagoWebhookRequestParser;
use App\Contracts\Finance\MercadoPagoConnectionSecretStore;
use App\Models\FinancialProviderConnection;
use DomainException;
use Illuminate\Support\Str;

final class MercadoPagoPointWebhookReceiptService
{
    public function __construct(
        private readonly MercadoPagoConnectionSecretStore $secretStore,
        private readonly MercadoPagoWebhookRequestParser $requestParser,
        private readonly MercadoPagoPointWebhookResolver $resolver
    ) {
    }

    public function accept(
        string $connectionPublicId,
        string $rawQuery,
        string $rawBody,
        ?string $contentType,
        string $xSignature,
        string $xRequestId
    ): MercadoPagoPointWebhookReceipt {
        $connectionPublicId = strtolower(
            trim($connectionPublicId)
        );

        if (! Str::isUuid($connectionPublicId)) {
            throw new DomainException(
                'La conexión Webhook Mercado Pago no es válida.'
            );
        }

        // Deliberately no implicit route binding here: public webhook requests
        // do not carry CurrentOrganization. The configured route UUID selects
        // an internal candidate; its own secret must still authenticate it.
        $connection = FinancialProviderConnection::query()
            ->where('public_id', $connectionPublicId)
            ->where('provider_key', 'mercado-pago')
            ->where('active', true)
            ->first();

        if (! $connection) {
            throw new DomainException(
                'La conexión Webhook Mercado Pago no está disponible.'
            );
        }

        $secrets = $this->secretStore->forConnection(
            $connection
        );

        $query = $this->requestParser->query($rawQuery);
        $body = $this->requestParser->body(
            $rawBody,
            $contentType
        );

        $notification = $this->resolver->authenticate(
            $xSignature,
            $xRequestId,
            $query,
            $body,
            $secrets->webhookSecret,
            $secrets->applicationId,
            $secrets->userId,
            $secrets->liveMode
        );

        return new MercadoPagoPointWebhookReceipt(
            connectionPublicId: $connectionPublicId,
            resourceId: $notification->resourceId,
            notificationId: $notification->notificationId
        );
    }
}
