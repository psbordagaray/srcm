<?php

namespace App\Domain\Finance;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\CurrentOrganization;
use App\Models\FinancialAccount;
use App\Models\FinancialExternalMovement;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final class ExternalFinancialMovementRecorder
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly AuditRecorder $audit
    ) {
    }

    public function record(
        FinancialAccount $account,
        ExternalFinancialMovementData $data,
        User $actor
    ): FinancialExternalMovement {
        $organizationId = $this->organizationId($actor);
        $normalized = $this->normalize($data);

        return DB::transaction(function () use (
            $organizationId,
            $account,
            $normalized,
            $actor
        ): FinancialExternalMovement {
            $lockedAccount = FinancialAccount::query()
                ->forOrganization($organizationId)
                ->whereKey($account->getKey())
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if (! $lockedAccount) {
                throw new DomainException(
                    'La cuenta financiera no está disponible en la organización activa.'
                );
            }

            if (
                $lockedAccount->currency_code
                    !== $normalized['currency_code']
            ) {
                throw new DomainException(
                    'La moneda del movimiento no coincide con la cuenta financiera.'
                );
            }

            $existing = FinancialExternalMovement::query()
                ->forOrganization($organizationId)
                ->where('financial_account_id', $lockedAccount->getKey())
                ->where('source', $normalized['source'])
                ->where('source_key', $normalized['source_key'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->fingerprint !== $normalized['fingerprint']) {
                    throw new DomainException(
                        'La misma operación externa fue recibida con contenido diferente.'
                    );
                }

                return $existing;
            }

            $movement = FinancialExternalMovement::query()->create([
                'organization_id' => $organizationId,
                'financial_account_id' => $lockedAccount->getKey(),
                ...$normalized,
                'imported_at' => CarbonImmutable::now(),
                'created_by_user_id' => $actor->getKey(),
                'created_at' => CarbonImmutable::now(),
            ]);

            $this->audit->record(
                $movement,
                'financial_external_movement_recorded',
                null,
                [
                    'financial_account_id' => $lockedAccount->getKey(),
                    'source' => $movement->source,
                    'source_key' => $movement->source_key,
                    'external_operation_id' =>
                        $movement->external_operation_id,
                    'direction' => $movement->direction,
                    'status' => $movement->status,
                    'currency_code' => $movement->currency_code,
                    'gross_amount_minor' => $movement->gross_amount_minor,
                    'fee_amount_minor' => $movement->fee_amount_minor,
                    'withholding_amount_minor' =>
                        $movement->withholding_amount_minor,
                    'net_amount_minor' => $movement->net_amount_minor,
                ]
            );

            return $movement->refresh();
        }, 3);
    }

    private function organizationId(User $actor): int
    {
        $organizationId = $this->currentOrganization->id($actor);
        $role = $this->currentOrganization->roleFor($actor);

        if (! ($role?->canReviewFinancialReconciliation() ?? false)) {
            throw new DomainException(
                'No posee permiso para registrar movimientos financieros externos.'
            );
        }

        return $organizationId;
    }

    /** @return array<string, mixed> */
    private function normalize(
        ExternalFinancialMovementData $data
    ): array {
        $sourceKey = trim($data->sourceKey);
        $currency = strtoupper(trim($data->currencyCode));
        $externalOperationId = filled($data->externalOperationId)
            ? trim((string) $data->externalOperationId)
            : null;
        $rawReference = filled($data->rawReference)
            ? trim((string) $data->rawReference)
            : null;

        if ($sourceKey === '' || mb_strlen($sourceKey) > 191) {
            throw new DomainException(
                'La clave de origen del movimiento externo no es válida.'
            );
        }

        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new DomainException(
                'La moneda del movimiento externo no es válida.'
            );
        }

        foreach ([
            'bruto' => $data->grossAmountMinor,
            'neto' => $data->netAmountMinor,
            'comisión' => $data->feeAmountMinor,
            'retención' => $data->withholdingAmountMinor,
        ] as $label => $amount) {
            if ($amount < 0) {
                throw new DomainException(
                    "El importe {$label} no puede ser negativo."
                );
            }
        }

        if ($data->grossAmountMinor <= 0) {
            throw new DomainException(
                'El importe bruto debe ser mayor que cero.'
            );
        }

        if (
            $data->netAmountMinor
                + $data->feeAmountMinor
                + $data->withholdingAmountMinor
            !== $data->grossAmountMinor
        ) {
            throw new DomainException(
                'Bruto debe ser igual a neto más comisión y retenciones.'
            );
        }

        if (
            $externalOperationId !== null
            && mb_strlen($externalOperationId) > 191
        ) {
            throw new DomainException(
                'El ID de operación externa supera la longitud admitida.'
            );
        }

        if ($rawReference !== null && mb_strlen($rawReference) > 500) {
            throw new DomainException(
                'La referencia externa supera la longitud admitida.'
            );
        }

        $occurredAt = $data->occurredAt
            ? CarbonImmutable::instance($data->occurredAt)
            : CarbonImmutable::now();

        $canonical = [
            'source' => $data->source->value,
            'source_key' => $sourceKey,
            'external_operation_id' => $externalOperationId,
            'direction' => $data->direction->value,
            'status' => $data->status->value,
            'currency_code' => $currency,
            'gross_amount_minor' => $data->grossAmountMinor,
            'fee_amount_minor' => $data->feeAmountMinor,
            'withholding_amount_minor' =>
                $data->withholdingAmountMinor,
            'net_amount_minor' => $data->netAmountMinor,
            'occurred_at' => $occurredAt->toIso8601String(),
            'raw_reference' => $rawReference,
        ];

        return [
            ...$canonical,
            'fingerprint' => hash(
                'sha256',
                json_encode(
                    $canonical,
                    JSON_UNESCAPED_SLASHES
                        | JSON_UNESCAPED_UNICODE
                        | JSON_THROW_ON_ERROR
                )
            ),
        ];
    }
}
