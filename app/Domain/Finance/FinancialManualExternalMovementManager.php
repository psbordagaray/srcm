<?php

namespace App\Domain\Finance;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\FinancialAccountType;
use App\Enums\FinancialMovementSource;
use App\Enums\FinancialMovementStatus;
use App\Models\FinancialAccount;
use App\Models\FinancialExternalMovement;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class FinancialManualExternalMovementManager
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly ExternalFinancialMovementRecorder $recorder,
        private readonly AuditRecorder $audit
    ) {
    }

    public function record(
        FinancialAccount $account,
        FinancialManualExternalMovementData $data,
        User $actor
    ): FinancialExternalMovement {
        $organizationId = $this->organizationId(
            $actor
        );

        $reason = trim($data->reason);

        if (
            mb_strlen($reason) < 10
            || mb_strlen($reason) > 500
        ) {
            throw new DomainException(
                'El registro manual requiere un motivo explícito de 10 a 500 caracteres.'
            );
        }

        if (! Str::isUuid($data->idempotencyKey)) {
            throw new DomainException(
                'La clave idempotente del registro manual no es válida.'
            );
        }

        return DB::transaction(function () use (
            $organizationId,
            $account,
            $data,
            $actor,
            $reason
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
                in_array(
                    $lockedAccount->type,
                    [
                        FinancialAccountType::CashBox,
                        FinancialAccountType::CashReserve,
                    ],
                    true
                )
            ) {
                throw new DomainException(
                    'Una cuenta de efectivo no admite movimientos financieros externos manuales.'
                );
            }

            $externalOperationId =
                filled($data->externalOperationId)
                    ? trim(
                        (string) $data
                            ->externalOperationId
                    )
                    : null;

            if ($externalOperationId !== null) {
                $existingByOperation =
                    FinancialExternalMovement::query()
                        ->forOrganization(
                            $organizationId
                        )
                        ->where(
                            'financial_account_id',
                            $lockedAccount->getKey()
                        )
                        ->where(
                            'external_operation_id',
                            $externalOperationId
                        )
                        ->where(
                            'status',
                            FinancialMovementStatus::Posted->value
                        )
                        ->lockForUpdate()
                        ->get();

                if ($existingByOperation->count() > 1) {
                    throw new DomainException(
                        'La operación externa '
                        .$externalOperationId
                        .' posee más de un hecho posted y requiere revisión antes de usar el fallback manual.'
                    );
                }

                $existing =
                    $existingByOperation->first();

                if ($existing) {
                    if (
                        ! $this->sameFinancialObservation(
                            $existing,
                            $lockedAccount,
                            $data
                        )
                    ) {
                        throw new DomainException(
                            'La operación externa '
                            .$externalOperationId
                            .' ya existe con contenido financiero diferente.'
                        );
                    }

                    $this->audit->record(
                        $existing,
                        'financial_manual_external_movement_deduplicated',
                        null,
                        [
                            'financial_account_id' =>
                                $lockedAccount
                                    ->getKey(),
                            'external_operation_id' =>
                                $externalOperationId,
                            'manual_reason' => $reason,
                        ]
                    );

                    return $existing;
                }
            }

            $sourceKey =
                'manual:'
                .$lockedAccount->public_id
                .':'.$data->idempotencyKey;

            $existingDelivery =
                FinancialExternalMovement::query()
                    ->forOrganization($organizationId)
                    ->where(
                        'financial_account_id',
                        $lockedAccount->getKey()
                    )
                    ->where(
                        'source',
                        FinancialMovementSource::Manual->value
                    )
                    ->where(
                        'source_key',
                        $sourceKey
                    )
                    ->lockForUpdate()
                    ->first();

            $movement = $this->recorder->record(
                $lockedAccount,
                new ExternalFinancialMovementData(
                    source:
                        FinancialMovementSource::Manual,
                    sourceKey: $sourceKey,
                    direction: $data->direction,
                    status:
                        FinancialMovementStatus::Posted,
                    currencyCode:
                        $lockedAccount->currency_code,
                    grossAmountMinor:
                        $data->grossAmountMinor,
                    netAmountMinor:
                        $data->netAmountMinor,
                    feeAmountMinor:
                        $data->feeAmountMinor,
                    withholdingAmountMinor:
                        $data->withholdingAmountMinor,
                    externalOperationId:
                        $externalOperationId,
                    rawReference:
                        filled($data->reference)
                            ? trim(
                                (string) $data->reference
                            )
                            : null,
                    occurredAt: $data->occurredAt
                ),
                $actor
            );

            if (! $existingDelivery) {
                $this->audit->record(
                    $movement,
                    'financial_manual_external_movement_recorded',
                    null,
                    [
                        'financial_account_id' =>
                            $lockedAccount->getKey(),
                        'source_key' => $sourceKey,
                        'external_operation_id' =>
                            $externalOperationId,
                        'manual_reason' => $reason,
                    ]
                );
            }

            return $movement;
        }, 3);
    }

    private function sameFinancialObservation(
        FinancialExternalMovement $existing,
        FinancialAccount $account,
        FinancialManualExternalMovementData $data
    ): bool {
        return $existing->direction
                === $data->direction
            && $existing->status
                === FinancialMovementStatus::Posted
            && $existing->currency_code
                === $account->currency_code
            && (int) $existing->gross_amount_minor
                === $data->grossAmountMinor
            && (int) $existing->fee_amount_minor
                === $data->feeAmountMinor
            && (int) $existing->withholding_amount_minor
                === $data->withholdingAmountMinor
            && (int) $existing->net_amount_minor
                === $data->netAmountMinor;
    }

    private function organizationId(
        User $actor
    ): int {
        $organizationId =
            $this->currentOrganization->id($actor);

        $role =
            $this->currentOrganization->roleFor($actor);

        if (
            ! (
                $role
                    ?->canReviewFinancialReconciliation()
                ?? false
            )
        ) {
            throw new DomainException(
                'No posee permiso para registrar movimientos financieros externos manuales.'
            );
        }

        return $organizationId;
    }
}
