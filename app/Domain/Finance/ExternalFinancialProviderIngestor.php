<?php

namespace App\Domain\Finance;

use App\Enums\FinancialMovementSource;
use App\Models\FinancialExternalMovement;
use App\Models\FinancialProviderConnection;
use DomainException;
use Illuminate\Support\Str;

final class ExternalFinancialProviderIngestor
{
    public function __construct(
        private readonly ExternalFinancialMovementRecorder $recorder
    ) {
    }

    public function ingest(
        FinancialProviderConnection $connection,
        FinancialMovementSource $source,
        ExternalFinancialProviderObservation $observation
    ): FinancialExternalMovement {
        if (
            ! in_array(
                $source,
                [
                    FinancialMovementSource::Api,
                    FinancialMovementSource::Webhook,
                    FinancialMovementSource::Polling,
                ],
                true
            )
        ) {
            throw new DomainException(
                'P5 admite sólo API, webhook o polling para ingestión automática.'
            );
        }

        $providerKey = $this->providerKey(
            $observation->providerKey
        );

        if ($providerKey !== $connection->provider_key) {
            throw new DomainException(
                'La observación pertenece a otro proveedor financiero.'
            );
        }

        $observationKey = trim($observation->observationKey);

        if (
            $observationKey === ''
            || mb_strlen($observationKey) > 170
            || preg_match(
                '/^[A-Za-z0-9][A-Za-z0-9._:\/-]*$/D',
                $observationKey
            ) !== 1
        ) {
            throw new DomainException(
                'La clave de observación externa no es válida.'
            );
        }

        $sourceKey = $providerKey.':'.$observationKey;

        if (mb_strlen($sourceKey) > 191) {
            throw new DomainException(
                'La clave idempotente del proveedor supera la longitud admitida.'
            );
        }

        $externalOperationId = trim(
            $observation->externalOperationId
        );

        if (
            $externalOperationId === ''
            || mb_strlen($externalOperationId) > 191
        ) {
            throw new DomainException(
                'La operación automática requiere un ID externo estable.'
            );
        }

        return $this->recorder->recordAutomated(
            $connection,
            new ExternalFinancialMovementData(
                source: $source,
                sourceKey: $sourceKey,
                direction: $observation->direction,
                status: $observation->status,
                currencyCode: $observation->currencyCode,
                grossAmountMinor:
                    $observation->grossAmountMinor,
                netAmountMinor:
                    $observation->netAmountMinor,
                feeAmountMinor:
                    $observation->feeAmountMinor,
                withholdingAmountMinor:
                    $observation->withholdingAmountMinor,
                externalOperationId:
                    $externalOperationId,
                rawReference:
                    $observation->rawReference,
                occurredAt:
                    $observation->occurredAt
            )
        );
    }

    private function providerKey(string $value): string
    {
        $value = Str::slug(trim($value));

        if (
            $value === ''
            || mb_strlen($value) > 100
        ) {
            throw new DomainException(
                'La clave del proveedor financiero no es válida.'
            );
        }

        return $value;
    }
}
