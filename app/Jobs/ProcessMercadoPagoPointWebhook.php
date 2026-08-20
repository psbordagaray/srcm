<?php

namespace App\Jobs;

use App\Adapters\Finance\MercadoPago\MercadoPagoPointWebhookResolver;
use App\Contracts\Finance\MercadoPagoConnectionSecretStore;
use App\Domain\Finance\ExternalFinancialProviderIngestor;
use App\Enums\FinancialMovementSource;
use App\Models\FinancialProviderConnection;
use DomainException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class ProcessMercadoPagoPointWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public bool $failOnTimeout = true;

    public ?string $correlationId = null;

    public function __construct(
        public readonly string $connectionPublicId,
        public readonly string $resourceId,
        public readonly ?string $notificationId,
        ?string $correlationId = null
    ) {
        if (is_string($correlationId) && Str::isUuid($correlationId)) {
            $this->correlationId = strtolower($correlationId);
        }
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [15, 60, 300, 900];
    }

    public function handle(
        MercadoPagoConnectionSecretStore $secretStore,
        MercadoPagoPointWebhookResolver $resolver,
        ExternalFinancialProviderIngestor $ingestor
    ): void {
        $context = [
            'job' => self::class,
            'integration' => 'mercado_pago_point',
        ];
        if ($this->correlationId !== null) {
            $context['correlation_id'] = $this->correlationId;
        }
        Log::shareContext($context);
        Log::info('integration.job_started');

        if (! Str::isUuid($this->connectionPublicId)) {
            throw new DomainException(
                'El job Mercado Pago no posee una conexión válida.'
            );
        }

        $connection = FinancialProviderConnection::query()
            ->where('public_id', $this->connectionPublicId)
            ->where('provider_key', 'mercado-pago')
            ->where('active', true)
            ->first();

        if (! $connection) {
            throw new DomainException(
                'La conexión Mercado Pago del job no está disponible.'
            );
        }

        $secrets = $secretStore->forConnection($connection);

        $observation = $resolver->resolveResource(
            $this->resourceId,
            $secrets->accessToken
        );

        $ingestor->ingest(
            $connection,
            FinancialMovementSource::Webhook,
            $observation
        );

        Log::info('integration.job_succeeded');
    }
}
