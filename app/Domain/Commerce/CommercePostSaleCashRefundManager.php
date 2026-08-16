<?php

namespace App\Domain\Commerce;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CashMovementDirection;
use App\Enums\CashMovementType;
use App\Enums\CashRegisterSessionStatus;
use App\Enums\CommercePaymentMethod;
use App\Enums\CommercePostSaleResolutionOutcome;
use App\Enums\CommerceSaleStatus;
use App\Enums\FinancialAccountType;
use App\Models\CashMovement;
use App\Models\CashRegisterSession;
use App\Models\CommercePayment;
use App\Models\CommercePostSaleCashRefundExecution;
use App\Models\CommercePostSaleResolution;
use App\Models\CommercePostSaleResolutionLine;
use App\Models\FinancialAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

final class CommercePostSaleCashRefundManager
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly AuditRecorder $audit
    ) {
    }

    public function execute(
        CommercePostSaleResolution $resolution,
        ?string $executionReference,
        ?string $executionNote,
        string $idempotencyKey,
        User $actor
    ): CommercePostSaleCashRefundExecution {
        $organizationId =
            $this->currentOrganization->id($actor);

        $role =
            $this->currentOrganization->roleFor($actor);

        if (
            ! (
                $role
                    ?->canExecuteCommercePostSaleCashRefund()
                ?? false
            )
        ) {
            throw new DomainException(
                'No posee permiso para ejecutar reembolsos de posventa en efectivo.'
            );
        }

        $executionReference =
            $this->optionalText(
                $executionReference,
                'La referencia de reembolso',
                180
            );

        $executionNote =
            $this->optionalText(
                $executionNote,
                'La nota de reembolso',
                1000
            );

        $idempotencyKey =
            Str::of($idempotencyKey)
                ->squish()
                ->toString();

        if (
            $idempotencyKey === ''
            || mb_strlen($idempotencyKey) > 180
        ) {
            throw new DomainException(
                'La clave idempotente del reembolso no es válida.'
            );
        }

        return DB::transaction(function () use (
            $resolution,
            $executionReference,
            $executionNote,
            $idempotencyKey,
            $actor,
            $organizationId
        ): CommercePostSaleCashRefundExecution {
            $locked =
                CommercePostSaleResolution::query()
                    ->forOrganization($organizationId)
                    ->whereKey($resolution->id)
                    ->lockForUpdate()
                    ->first();

            if (! $locked) {
                throw new DomainException(
                    'La resolución de posventa no pertenece a la organización activa.'
                );
            }

            $existingByKey =
                CommercePostSaleCashRefundExecution::query()
                    ->forOrganization($organizationId)
                    ->where(
                        'idempotency_key',
                        $idempotencyKey
                    )
                    ->lockForUpdate()
                    ->first();

            if ($existingByKey) {
                return $this->existingExecution(
                    $existingByKey,
                    $locked,
                    $executionReference,
                    $executionNote,
                    $actor
                );
            }

            $existingByResolution =
                CommercePostSaleCashRefundExecution::query()
                    ->forOrganization($organizationId)
                    ->where(
                        'commerce_post_sale_resolution_id',
                        $locked->id
                    )
                    ->lockForUpdate()
                    ->first();

            if ($existingByResolution) {
                throw new DomainException(
                    'La resolución de reembolso ya fue consumida por otra ejecución.'
                );
            }

            if (
                $locked->outcome
                    !== CommercePostSaleResolutionOutcome::Refund
            ) {
                throw new DomainException(
                    'Sólo una resolución de reembolso puede ejecutar una salida de efectivo.'
                );
            }

            if (
                $locked->resolved_by_user_id === null
                || (int) $locked->resolved_by_user_id
                    === (int) $actor->id
            ) {
                throw new DomainException(
                    'Quien resolvió económicamente la posventa no puede ejecutar el reembolso en efectivo.'
                );
            }

            $locked->loadMissing(
                'request.sale',
                'preferredOriginalPayment'
            );

            $sale =
                $locked->request?->sale;

            if (
                ! $sale
                || $sale->status
                    !== CommerceSaleStatus::Confirmed
                || $sale->currency_code
                    !== $locked->currency_code
            ) {
                throw new DomainException(
                    'El reembolso requiere una venta original confirmada y consistente.'
                );
            }

            if (
                $locked->preferred_original_payment_id
                    === null
            ) {
                throw new DomainException(
                    'El reembolso en efectivo requiere seleccionar explícitamente el pago original en efectivo.'
                );
            }

            $payment =
                CommercePayment::query()
                    ->forOrganization($organizationId)
                    ->whereKey(
                        $locked
                            ->preferred_original_payment_id
                    )
                    ->where(
                        'commerce_sale_id',
                        $sale->id
                    )
                    ->lockForUpdate()
                    ->first();

            if (
                ! $payment
                || $payment->method
                    !== CommercePaymentMethod::Cash
                || $payment->financial_account_id
                    === null
                || $payment->amount_minor <= 0
            ) {
                throw new DomainException(
                    'El medio original elegido no es un cobro en efectivo ejecutable.'
                );
            }

            $amountMinor =
                $this->recognizedAmountMinor(
                    $locked,
                    $organizationId
                );

            $alreadyRefunded =
                (int)
                CommercePostSaleCashRefundExecution::query()
                    ->forOrganization($organizationId)
                    ->where(
                        'original_commerce_payment_id',
                        $payment->id
                    )
                    ->lockForUpdate()
                    ->get(['amount_minor'])
                    ->sum('amount_minor');

            $remainingOriginal =
                max(
                    0,
                    (int) $payment->amount_minor
                    - $alreadyRefunded
                );

            if (
                $amountMinor <= 0
                || $amountMinor > $remainingOriginal
            ) {
                throw new DomainException(
                    'El reembolso supera el saldo todavía reintegrable del pago original.'
                );
            }

            $origin =
                FinancialAccount::query()
                    ->forOrganization($organizationId)
                    ->whereKey(
                        $payment
                            ->financial_account_id
                    )
                    ->where('active', true)
                    ->where(
                        'currency_code',
                        $locked->currency_code
                    )
                    ->lockForUpdate()
                    ->first();

            if (
                ! $origin
                || $origin->type
                    !== FinancialAccountType::CashBox
            ) {
                throw new DomainException(
                    'El pago original ya no conserva una cuenta de caja activa compatible.'
                );
            }

            $session =
                CashRegisterSession::query()
                    ->forOrganization($organizationId)
                    ->where(
                        'opened_by_user_id',
                        $actor->id
                    )
                    ->where(
                        'status',
                        CashRegisterSessionStatus::Open
                    )
                    ->where(
                        'currency_code',
                        $locked->currency_code
                    )
                    ->whereHas(
                        'register',
                        fn ($query) => $query
                            ->where('active', true)
                            ->where(
                                'financial_account_id',
                                $origin->id
                            )
                    )
                    ->with('register')
                    ->lockForUpdate()
                    ->first();

            if (
                ! $session
                || ! $session->register
            ) {
                throw new DomainException(
                    'Para ejecutar el reembolso necesitás un turno abierto propio sobre la misma caja del cobro original.'
                );
            }

            $expectedBefore =
                $this->lockedExpectedAmountMinor(
                    $session
                );

            if ($amountMinor > $expectedBefore) {
                throw new DomainException(
                    'El reembolso supera el efectivo esperado actual del turno.'
                );
            }

            $now =
                CarbonImmutable::now('UTC');

            $fingerprint =
                $this->fingerprint([
                    'organization_id' =>
                        $organizationId,
                    'commerce_post_sale_resolution_id' =>
                        (int) $locked->id,
                    'resolution_fingerprint' =>
                        (string) $locked->fingerprint,
                    'original_commerce_payment_id' =>
                        (int) $payment->id,
                    'origin_financial_account_id' =>
                        (int) $origin->id,
                    'cash_register_session_id' =>
                        (int) $session->id,
                    'cash_register_id' =>
                        (int) $session->cash_register_id,
                    'executed_by_user_id' =>
                        (int) $actor->id,
                    'amount_minor' =>
                        $amountMinor,
                    'currency_code' =>
                        $locked->currency_code,
                    'execution_reference' =>
                        $executionReference,
                    'execution_note' =>
                        $executionNote,
                ]);

            $execution =
                CommercePostSaleCashRefundExecution::query()
                    ->create([
                        'organization_id' =>
                            $organizationId,
                        'commerce_post_sale_resolution_id' =>
                            $locked->id,
                        'original_commerce_payment_id' =>
                            $payment->id,
                        'origin_financial_account_id' =>
                            $origin->id,
                        'cash_register_session_id' =>
                            $session->id,
                        'cash_register_id' =>
                            $session->cash_register_id,
                        'executed_by_user_id' =>
                            $actor->id,
                        'amount_minor' =>
                            $amountMinor,
                        'currency_code' =>
                            $locked->currency_code,
                        'execution_reference' =>
                            $executionReference,
                        'execution_note' =>
                            $executionNote,
                        'idempotency_key' =>
                            $idempotencyKey,
                        'fingerprint' =>
                            $fingerprint,
                        'executed_at' =>
                            $now,
                        'created_at' =>
                            $now,
                    ]);

            $movementFingerprint =
                $this->fingerprint([
                    'organization_id' =>
                        $organizationId,
                    'post_sale_cash_refund_execution_id' =>
                        (int) $execution->id,
                    'cash_register_session_id' =>
                        (int) $session->id,
                    'cash_register_id' =>
                        (int) $session->cash_register_id,
                    'financial_account_id' =>
                        (int) $origin->id,
                    'direction' =>
                        CashMovementDirection::Out
                            ->value,
                    'type' =>
                        CashMovementType::PostSaleRefund
                            ->value,
                    'amount_minor' =>
                        $amountMinor,
                    'currency_code' =>
                        $locked->currency_code,
                    'recorded_by_user_id' =>
                        (int) $actor->id,
                ]);

            $movement =
                CashMovement::query()
                    ->create([
                        'organization_id' =>
                            $organizationId,
                        'cash_register_session_id' =>
                            $session->id,
                        'cash_register_id' =>
                            $session->cash_register_id,
                        'financial_account_id' =>
                            $origin->id,
                        'destination_financial_account_id' =>
                            null,
                        'cash_security_drop_request_id' =>
                            null,
                        'purchase_payment_execution_id' =>
                            null,
                        'post_sale_cash_refund_execution_id' =>
                            $execution->id,
                        'commerce_payment_id' =>
                            null,
                        'direction' =>
                            CashMovementDirection::Out,
                        'type' =>
                            CashMovementType::PostSaleRefund,
                        'reason_code' =>
                            null,
                        'note' =>
                            null,
                        'amount_minor' =>
                            $amountMinor,
                        'currency_code' =>
                            $locked->currency_code,
                        'idempotency_key' =>
                            'post-sale-cash-refund:'
                            .$execution->id,
                        'fingerprint' =>
                            $movementFingerprint,
                        'recorded_by_user_id' =>
                            $actor->id,
                        'occurred_at' =>
                            $now,
                        'created_at' =>
                            $now,
                    ]);

            $this->audit->record(
                $movement,
                'post_sale_cash_refund_cash_recorded',
                null,
                [
                    'commerce_post_sale_cash_refund_execution_id' =>
                        (int) $execution->id,
                    'commerce_post_sale_resolution_id' =>
                        (int) $locked->id,
                    'original_commerce_payment_id' =>
                        (int) $payment->id,
                    'cash_register_session_id' =>
                        (int) $session->id,
                    'cash_register_id' =>
                        (int) $session->cash_register_id,
                    'financial_account_id' =>
                        (int) $origin->id,
                    'resolved_by_user_id' =>
                        (int) $locked
                            ->resolved_by_user_id,
                    'executed_by_user_id' =>
                        (int) $actor->id,
                    'amount_minor' =>
                        $amountMinor,
                    'currency_code' =>
                        $locked->currency_code,
                    'expected_before_minor' =>
                        $expectedBefore,
                    'expected_after_minor' =>
                        $expectedBefore
                        - $amountMinor,
                ]
            );

            $this->audit->record(
                $execution,
                'commerce_post_sale_cash_refund_executed',
                null,
                [
                    'commerce_post_sale_resolution_id' =>
                        (int) $locked->id,
                    'commerce_post_sale_request_id' =>
                        (int) $locked
                            ->commerce_post_sale_request_id,
                    'commerce_sale_id' =>
                        (int) $sale->id,
                    'original_commerce_payment_id' =>
                        (int) $payment->id,
                    'cash_movement_id' =>
                        (int) $movement->id,
                    'resolved_by_user_id' =>
                        (int) $locked
                            ->resolved_by_user_id,
                    'executed_by_user_id' =>
                        (int) $actor->id,
                    'amount_minor' =>
                        $amountMinor,
                    'currency_code' =>
                        $locked->currency_code,
                ]
            );

            return $execution->refresh()->load([
                'resolution.request.sale',
                'originalPayment',
                'originFinancialAccount',
                'cashRegisterSession.register',
                'executedBy',
                'cashMovement',
            ]);
        }, 3);
    }

    private function existingExecution(
        CommercePostSaleCashRefundExecution $existing,
        CommercePostSaleResolution $resolution,
        ?string $executionReference,
        ?string $executionNote,
        User $actor
    ): CommercePostSaleCashRefundExecution {
        if (
            (int) $existing
                ->commerce_post_sale_resolution_id
                !== (int) $resolution->id
            || (int) $existing
                ->executed_by_user_id
                !== (int) $actor->id
            || $existing->execution_reference
                !== $executionReference
            || $existing->execution_note
                !== $executionNote
        ) {
            throw new DomainException(
                'La misma clave de reembolso fue utilizada con otros hechos.'
            );
        }

        $movement =
            CashMovement::query()
                ->forOrganization(
                    $resolution->organization_id
                )
                ->where(
                    'post_sale_cash_refund_execution_id',
                    $existing->id
                )
                ->first();

        if (! $movement) {
            throw new DomainException(
                'La ejecución registrada no posee su movimiento de caja; requiere revisión.'
            );
        }

        return $existing->refresh()->load([
            'resolution.request.sale',
            'originalPayment',
            'originFinancialAccount',
            'cashRegisterSession.register',
            'executedBy',
            'cashMovement',
        ]);
    }

    private function recognizedAmountMinor(
        CommercePostSaleResolution $resolution,
        int $organizationId
    ): int {
        $lines =
            CommercePostSaleResolutionLine::query()
                ->where(
                    'organization_id',
                    $organizationId
                )
                ->where(
                    'commerce_post_sale_resolution_id',
                    $resolution->id
                )
                ->orderBy('id')
                ->lockForUpdate()
                ->get([
                    'recognized_amount_minor',
                ]);

        if ($lines->isEmpty()) {
            throw new DomainException(
                'La resolución no posee valor reconocido reembolsable.'
            );
        }

        $amountMinor = 0;

        foreach ($lines as $line) {
            $amount =
                (int) $line
                    ->recognized_amount_minor;

            if (
                $amount < 0
                || $amountMinor
                    > PHP_INT_MAX - $amount
            ) {
                throw new DomainException(
                    'El valor reconocido supera el importe admitido.'
                );
            }

            $amountMinor += $amount;
        }

        return $amountMinor;
    }

    private function lockedExpectedAmountMinor(
        CashRegisterSession $session
    ): int {
        $netMinor =
            (int)
            CashMovement::query()
                ->where(
                    'cash_register_session_id',
                    $session->id
                )
                ->lockForUpdate()
                ->get([
                    'direction',
                    'amount_minor',
                ])
                ->sum(
                    fn (CashMovement $movement): int =>
                        $movement->direction
                            === CashMovementDirection::In
                            ? $movement->amount_minor
                            : -$movement->amount_minor
                );

        return
            (int) $session
                ->opening_amount_minor
            + $netMinor;
    }

    private function optionalText(
        ?string $value,
        string $label,
        int $max
    ): ?string {
        if ($value === null) {
            return null;
        }

        $value =
            Str::of($value)
                ->squish()
                ->toString();

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $max) {
            throw new DomainException(
                $label.' supera la longitud admitida.'
            );
        }

        return $value;
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
                'No se pudo construir la huella del reembolso.',
                previous: $exception
            );
        }
    }
}
