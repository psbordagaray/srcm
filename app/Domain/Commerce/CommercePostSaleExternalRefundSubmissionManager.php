<?php

namespace App\Domain\Commerce;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Finance\ExternalFinancialProviderIngestor;
use App\Domain\Finance\ExternalFinancialProviderObservation;
use App\Domain\Finance\FinancialProviderAutomationGate;
use App\Domain\Finance\FinancialProviderRefundAdapterRegistry;
use App\Domain\Finance\FinancialProviderRefundRequest;
use App\Enums\FinancialMovementDirection;
use App\Enums\FinancialMovementSource;
use App\Enums\FinancialMovementStatus;
use App\Enums\FinancialProviderCapability;
use App\Models\CommercePostSaleExternalRefundDispatch;
use App\Models\CommercePostSaleExternalRefundEvidence;
use App\Models\CommercePostSaleExternalRefundInstruction;
use App\Models\FinancialProviderConnection;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use JsonException;

final class CommercePostSaleExternalRefundSubmissionManager
{
    public function __construct(
        private readonly FinancialProviderRefundAdapterRegistry $adapters,
        private readonly FinancialProviderAutomationGate $automationGate,
        private readonly ExternalFinancialProviderIngestor $ingestor,
        private readonly AuditRecorder $audit
    ) {
    }

    public function submit(
        CommercePostSaleExternalRefundInstruction $instruction
    ): CommercePostSaleExternalRefundEvidence {
        $instruction =
            CommercePostSaleExternalRefundInstruction::query()
                ->whereKey($instruction->id)
                ->with([
                    'originalPayment',
                    'financialAccount',
                    'providerConnection',
                ])
                ->first();

        if (! $instruction) {
            throw new DomainException(
                'La instrucción de reembolso externo no existe.'
            );
        }

        $connection =
            $instruction->providerConnection;

        if (! $connection) {
            throw new DomainException(
                'La instrucción no conserva su conexión financiera.'
            );
        }

        $adapter =
            $this->adapters->adapterFor(
                $connection->provider_key
            );

        if (
            $adapter->providerKey()
                !== $connection->provider_key
        ) {
            throw new DomainException(
                'El adapter de reembolso no coincide con el proveedor conectado.'
            );
        }

        $dispatch =
            $this->prepareDispatch(
                $instruction
            );

        $existingEvidence =
            $dispatch->evidence()
                ->with('financialMovement')
                ->first();

        if ($existingEvidence) {
            return $existingEvidence;
        }

        $payment =
            $instruction->originalPayment;

        if (
            ! $payment
            || blank(
                $payment->external_operation_id
            )
        ) {
            throw new DomainException(
                'La operación externa original ya no está disponible.'
            );
        }

        $observation =
            $adapter->submitRefund(
                $connection,
                new FinancialProviderRefundRequest(
                    instructionPublicId:
                        $instruction->public_id,
                    originalExternalOperationId:
                        (string)
                        $payment->external_operation_id,
                    amountMinor:
                        (int) $instruction
                            ->amount_minor,
                    currencyCode:
                        $instruction
                            ->currency_code,
                    providerIdempotencyKey:
                        $dispatch
                            ->provider_idempotency_key
                )
            );

        return $this->recordObservation(
            $dispatch,
            FinancialMovementSource::Api,
            $observation
        );
    }

    public function recordObservation(
        CommercePostSaleExternalRefundDispatch $dispatch,
        FinancialMovementSource $source,
        ExternalFinancialProviderObservation $observation
    ): CommercePostSaleExternalRefundEvidence {
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
                'La evidencia externa automática sólo admite API, webhook o polling.'
            );
        }

        return DB::transaction(function () use (
            $dispatch,
            $source,
            $observation
        ): CommercePostSaleExternalRefundEvidence {
            $lockedDispatch =
                CommercePostSaleExternalRefundDispatch::query()
                    ->whereKey($dispatch->id)
                    ->with([
                        'instruction.originalPayment',
                        'providerConnection',
                        'financialAccount',
                    ])
                    ->lockForUpdate()
                    ->first();

            if (! $lockedDispatch) {
                throw new DomainException(
                    'El despacho de reembolso externo no existe.'
                );
            }

            $instruction =
                $lockedDispatch->instruction;

            $connection =
                $lockedDispatch->providerConnection;

            if (
                ! $instruction
                || ! $connection
                || (int) $instruction
                    ->financial_provider_connection_id
                    !== (int) $connection->id
                || (int) $instruction
                    ->financial_account_id
                    !== (int) $lockedDispatch
                        ->financial_account_id
                || (int) $connection
                    ->financial_account_id
                    !== (int) $lockedDispatch
                        ->financial_account_id
                || $connection->provider_key
                    !== $lockedDispatch
                        ->provider_key
            ) {
                throw new DomainException(
                    'El despacho ya no conserva su identidad financiera.'
                );
            }

            $this->assertObservationMatchesInstruction(
                $lockedDispatch,
                $observation
            );

            $movement =
                $this->ingestor->ingest(
                    $connection,
                    $source,
                    $observation
                );

            $fingerprint =
                $this->fingerprint([
                    'commerce_post_sale_external_refund_dispatch_id' =>
                        (int) $lockedDispatch->id,
                    'financial_external_movement_id' =>
                        (int) $movement->id,
                    'movement_fingerprint' =>
                        $movement->fingerprint,
                    'source' =>
                        $source->value,
                ]);

            $existing =
                CommercePostSaleExternalRefundEvidence::query()
                    ->forOrganization(
                        (int) $lockedDispatch
                            ->organization_id
                    )
                    ->where(
                        'financial_external_movement_id',
                        $movement->id
                    )
                    ->lockForUpdate()
                    ->first();

            if ($existing) {
                if (
                    (int) $existing
                        ->commerce_post_sale_external_refund_dispatch_id
                        !== (int) $lockedDispatch->id
                    || $existing->fingerprint
                        !== $fingerprint
                ) {
                    throw new DomainException(
                        'El movimiento financiero externo ya fue atribuido a otro reembolso.'
                    );
                }

                return $existing
                    ->load([
                        'dispatch.instruction',
                        'financialMovement',
                    ]);
            }

            $now =
                CarbonImmutable::now('UTC');

            $evidence =
                CommercePostSaleExternalRefundEvidence::query()
                    ->create([
                        'organization_id' =>
                            $lockedDispatch
                                ->organization_id,
                        'commerce_post_sale_external_refund_dispatch_id' =>
                            $lockedDispatch->id,
                        'financial_external_movement_id' =>
                            $movement->id,
                        'source' =>
                            $source,
                        'fingerprint' =>
                            $fingerprint,
                        'observed_at' =>
                            $movement->occurred_at
                            ?? $now,
                        'created_at' =>
                            $now,
                    ]);

            $this->audit->record(
                $evidence,
                'commerce_post_sale_external_refund_evidence_recorded',
                null,
                [
                    'commerce_post_sale_external_refund_dispatch_id' =>
                        (int) $lockedDispatch->id,
                    'commerce_post_sale_external_refund_instruction_id' =>
                        (int) $instruction->id,
                    'financial_provider_connection_id' =>
                        (int) $connection->id,
                    'financial_external_movement_id' =>
                        (int) $movement->id,
                    'provider_key' =>
                        $connection->provider_key,
                    'external_operation_id' =>
                        $movement
                            ->external_operation_id,
                    'source' =>
                        $source,
                    'status' =>
                        $movement->status,
                    'direction' =>
                        $movement->direction,
                    'amount_minor' =>
                        (int) $movement
                            ->gross_amount_minor,
                    'currency_code' =>
                        $movement
                            ->currency_code,
                ]
            );

            return $evidence
                ->refresh()
                ->load([
                    'dispatch.instruction',
                    'financialMovement',
                ]);
        }, 3);
    }

    private function prepareDispatch(
        CommercePostSaleExternalRefundInstruction $instruction
    ): CommercePostSaleExternalRefundDispatch {
        return DB::transaction(function () use (
            $instruction
        ): CommercePostSaleExternalRefundDispatch {
            $locked =
                CommercePostSaleExternalRefundInstruction::query()
                    ->whereKey($instruction->id)
                    ->with([
                        'originalPayment',
                        'financialAccount',
                        'providerConnection',
                    ])
                    ->lockForUpdate()
                    ->first();

            if (! $locked) {
                throw new DomainException(
                    'La instrucción de reembolso externo no existe.'
                );
            }

            $connection =
                $locked->providerConnection;

            $account =
                $locked->financialAccount;

            $payment =
                $locked->originalPayment;

            if (
                ! $connection
                || ! $account
                || ! $payment
                || ! $connection->active
                || ! $account->active
                || (int) $connection
                    ->financial_account_id
                    !== (int) $account->id
                || (int) $locked
                    ->financial_account_id
                    !== (int) $account->id
                || blank(
                    $payment->external_operation_id
                )
                || (int) $payment->id
                    !== (int) $locked
                        ->original_commerce_payment_id
                || (int) $locked->amount_minor
                    <= 0
            ) {
                throw new DomainException(
                    'La instrucción ya no conserva condiciones válidas para despacho.'
                );
            }

            $providerIdempotencyKey =
                'srcm-refund:'
                .$locked->public_id;

            if (
                mb_strlen(
                    $providerIdempotencyKey
                ) > 180
            ) {
                throw new DomainException(
                    'La clave idempotente provider-neutral supera la longitud admitida.'
                );
            }

            $fingerprint =
                $this->fingerprint([
                    'organization_id' =>
                        (int) $locked
                            ->organization_id,
                    'commerce_post_sale_external_refund_instruction_id' =>
                        (int) $locked->id,
                    'instruction_fingerprint' =>
                        $locked->fingerprint,
                    'financial_provider_connection_id' =>
                        (int) $connection->id,
                    'financial_account_id' =>
                        (int) $account->id,
                    'provider_key' =>
                        $connection->provider_key,
                    'provider_idempotency_key' =>
                        $providerIdempotencyKey,
                    'amount_minor' =>
                        (int) $locked
                            ->amount_minor,
                    'currency_code' =>
                        $locked
                            ->currency_code,
                    'original_external_operation_id' =>
                        (string) $payment
                            ->external_operation_id,
                ]);

            $existing =
                CommercePostSaleExternalRefundDispatch::query()
                    ->forOrganization(
                        (int) $locked
                            ->organization_id
                    )
                    ->where(
                        'commerce_post_sale_external_refund_instruction_id',
                        $locked->id
                    )
                    ->lockForUpdate()
                    ->first();

            if ($existing) {
                if (
                    $existing->fingerprint
                        !== $fingerprint
                    || $existing
                        ->provider_idempotency_key
                        !== $providerIdempotencyKey
                ) {
                    throw new DomainException(
                        'El despacho existente no coincide con la instrucción inmutable.'
                    );
                }

                return $existing
                    ->load([
                        'instruction',
                        'providerConnection',
                        'financialAccount',
                        'evidence.financialMovement',
                    ]);
            }

            $this->automationGate
                ->assertCanAutomate(
                    $connection,
                    FinancialProviderCapability::Refund
                );

            $dispatch =
                CommercePostSaleExternalRefundDispatch::query()
                    ->create([
                        'organization_id' =>
                            $locked
                                ->organization_id,
                        'commerce_post_sale_external_refund_instruction_id' =>
                            $locked->id,
                        'financial_provider_connection_id' =>
                            $connection->id,
                        'financial_account_id' =>
                            $account->id,
                        'provider_key' =>
                            $connection->provider_key,
                        'provider_idempotency_key' =>
                            $providerIdempotencyKey,
                        'fingerprint' =>
                            $fingerprint,
                        'created_at' =>
                            CarbonImmutable::now(
                                'UTC'
                            ),
                    ]);

            $this->audit->record(
                $dispatch,
                'commerce_post_sale_external_refund_dispatch_prepared',
                null,
                [
                    'commerce_post_sale_external_refund_instruction_id' =>
                        (int) $locked->id,
                    'financial_provider_connection_id' =>
                        (int) $connection->id,
                    'financial_account_id' =>
                        (int) $account->id,
                    'provider_key' =>
                        $connection->provider_key,
                    'amount_minor' =>
                        (int) $locked
                            ->amount_minor,
                    'currency_code' =>
                        $locked
                            ->currency_code,
                ]
            );

            return $dispatch
                ->refresh()
                ->load([
                    'instruction',
                    'providerConnection',
                    'financialAccount',
                    'evidence.financialMovement',
                ]);
        }, 3);
    }

    private function assertObservationMatchesInstruction(
        CommercePostSaleExternalRefundDispatch $dispatch,
        ExternalFinancialProviderObservation $observation
    ): void {
        $instruction =
            $dispatch->instruction;

        if (! $instruction) {
            throw new DomainException(
                'El despacho no conserva su instrucción.'
            );
        }

        if (
            $observation->providerKey
                !== $dispatch->provider_key
        ) {
            throw new DomainException(
                'La evidencia pertenece a otro proveedor.'
            );
        }

        if (
            $observation->direction
                !== FinancialMovementDirection::Debit
        ) {
            throw new DomainException(
                'Un reembolso externo debe observarse como egreso.'
            );
        }

        if (
            ! in_array(
                $observation->status,
                [
                    FinancialMovementStatus::Pending,
                    FinancialMovementStatus::Posted,
                    FinancialMovementStatus::Failed,
                    FinancialMovementStatus::Reversed,
                ],
                true
            )
        ) {
            throw new DomainException(
                'El estado externo del reembolso no es admitido.'
            );
        }

        if (
            strtoupper(
                trim(
                    $observation->currencyCode
                )
            )
                !== $instruction->currency_code
        ) {
            throw new DomainException(
                'La moneda observada no coincide con la instrucción.'
            );
        }

        if (
            $observation->grossAmountMinor
                !== (int) $instruction
                    ->amount_minor
        ) {
            throw new DomainException(
                'El importe observado no coincide con el reembolso instruido.'
            );
        }

        if (
            trim(
                $observation
                    ->externalOperationId
            ) === ''
        ) {
            throw new DomainException(
                'La evidencia del reembolso requiere identidad externa estable.'
            );
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function fingerprint(
        array $payload
    ): string {
        try {
            return hash(
                'sha256',
                json_encode(
                    $payload,
                    JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                )
            );
        } catch (JsonException $exception) {
            throw new DomainException(
                'No se pudo construir la huella del despacho de reembolso externo.',
                previous: $exception
            );
        }
    }
}
