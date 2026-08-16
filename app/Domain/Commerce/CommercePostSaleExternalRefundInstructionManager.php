<?php

namespace App\Domain\Commerce;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Finance\FinancialProviderAutomationGate;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CommercePaymentMethod;
use App\Enums\CommercePostSaleResolutionOutcome;
use App\Enums\CommerceSaleStatus;
use App\Enums\FinancialAccountType;
use App\Enums\FinancialProviderCapability;
use App\Models\CommercePayment;
use App\Models\CommercePostSaleExternalRefundInstruction;
use App\Models\CommercePostSaleResolution;
use App\Models\CommercePostSaleResolutionLine;
use App\Models\FinancialAccount;
use App\Models\FinancialProviderConnection;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use JsonException;

final class CommercePostSaleExternalRefundInstructionManager
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly FinancialProviderAutomationGate $automationGate,
        private readonly AuditRecorder $audit
    ) {
    }

    public function request(
        CommercePostSaleResolution $resolution,
        string $idempotencyKey,
        User $actor
    ): CommercePostSaleExternalRefundInstruction {
        $organizationId =
            $this->currentOrganization->id($actor);

        $role =
            $this->currentOrganization->roleFor($actor);

        if (
            ! (
                $role
                    ?->canExecuteCommercePostSaleExternalRefund()
                ?? false
            )
        ) {
            throw new DomainException(
                'No posee permiso para instruir reembolsos externos de posventa.'
            );
        }

        $idempotencyKey =
            trim($idempotencyKey);

        if (
            $idempotencyKey === ''
            || mb_strlen($idempotencyKey) > 180
        ) {
            throw new DomainException(
                'La clave idempotente del reembolso externo no es válida.'
            );
        }

        return DB::transaction(function () use (
            $resolution,
            $idempotencyKey,
            $actor,
            $organizationId
        ): CommercePostSaleExternalRefundInstruction {
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

            if (
                $locked->outcome
                    !== CommercePostSaleResolutionOutcome::Refund
            ) {
                throw new DomainException(
                    'Sólo una resolución de reembolso puede originar una instrucción externa.'
                );
            }

            if (
                $locked->resolved_by_user_id === null
                || (int) $locked->resolved_by_user_id
                    === (int) $actor->id
            ) {
                throw new DomainException(
                    'Quien resolvió económicamente la posventa no puede instruir su reembolso externo.'
                );
            }

            $locked->loadMissing(
                'request.sale'
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
                    'El reembolso externo requiere una venta original confirmada y consistente.'
                );
            }

            if (
                $locked->preferred_original_payment_id
                    === null
            ) {
                throw new DomainException(
                    'El reembolso externo requiere seleccionar explícitamente el pago original.'
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
                    === CommercePaymentMethod::Cash
                || $payment->financial_account_id
                    === null
                || blank(
                    $payment->external_operation_id
                )
                || $payment->amount_minor <= 0
            ) {
                throw new DomainException(
                    'El pago original no conserva una operación externa reembolsable.'
                );
            }

            $account =
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
                ! $account
                || in_array(
                    $account->type,
                    [
                        FinancialAccountType::CashBox,
                        FinancialAccountType::CashReserve,
                    ],
                    true
                )
            ) {
                throw new DomainException(
                    'La cuenta del pago original no admite reembolso externo.'
                );
            }

            $connection =
                FinancialProviderConnection::query()
                    ->forOrganization($organizationId)
                    ->where(
                        'financial_account_id',
                        $account->id
                    )
                    ->where('active', true)
                    ->lockForUpdate()
                    ->first();

            if (! $connection) {
                throw new DomainException(
                    'La cuenta del pago original no posee una conexión externa activa.'
                );
            }

            $amountMinor =
                $this->recognizedAmountMinor(
                    $locked,
                    $organizationId
                );

            if (
                $amountMinor <= 0
                || $amountMinor
                    > (int) $payment->amount_minor
            ) {
                throw new DomainException(
                    'El importe instruido no es reembolsable contra el pago original.'
                );
            }

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
                    'original_external_operation_id' =>
                        (string) $payment
                            ->external_operation_id,
                    'financial_account_id' =>
                        (int) $account->id,
                    'financial_provider_connection_id' =>
                        (int) $connection->id,
                    'provider_key' =>
                        $connection->provider_key,
                    'requested_by_user_id' =>
                        (int) $actor->id,
                    'amount_minor' =>
                        $amountMinor,
                    'currency_code' =>
                        $locked->currency_code,
                ]);

            $existingByKey =
                CommercePostSaleExternalRefundInstruction::query()
                    ->forOrganization($organizationId)
                    ->where(
                        'idempotency_key',
                        $idempotencyKey
                    )
                    ->lockForUpdate()
                    ->first();

            if ($existingByKey) {
                if (
                    ! hash_equals(
                        (string)
                            $existingByKey
                                ->fingerprint,
                        $fingerprint
                    )
                ) {
                    throw new DomainException(
                        'La clave idempotente del reembolso externo ya fue utilizada con otros hechos.'
                    );
                }

                return $existingByKey
                    ->load([
                        'resolution.request.sale',
                        'originalPayment',
                        'financialAccount',
                        'providerConnection',
                        'requestedBy',
                    ]);
            }

            $existingByResolution =
                CommercePostSaleExternalRefundInstruction::query()
                    ->forOrganization($organizationId)
                    ->where(
                        'commerce_post_sale_resolution_id',
                        $locked->id
                    )
                    ->lockForUpdate()
                    ->first();

            if ($existingByResolution) {
                throw new DomainException(
                    'La resolución ya posee otra instrucción de reembolso externo.'
                );
            }

            $alreadyReserved =
                (int)
                CommercePostSaleExternalRefundInstruction::query()
                    ->forOrganization($organizationId)
                    ->where(
                        'original_commerce_payment_id',
                        $payment->id
                    )
                    ->lockForUpdate()
                    ->get(['amount_minor'])
                    ->sum('amount_minor');

            if (
                $alreadyReserved
                    > PHP_INT_MAX - $amountMinor
                || $alreadyReserved
                    + $amountMinor
                    > (int) $payment->amount_minor
            ) {
                throw new DomainException(
                    'Las instrucciones acumuladas superarían el importe del pago original.'
                );
            }

            $this->automationGate
                ->assertCanAutomate(
                    $connection,
                    FinancialProviderCapability::Refund
                );

            $now =
                CarbonImmutable::now('UTC');

            $instruction =
                CommercePostSaleExternalRefundInstruction::query()
                    ->create([
                        'organization_id' =>
                            $organizationId,
                        'commerce_post_sale_resolution_id' =>
                            $locked->id,
                        'original_commerce_payment_id' =>
                            $payment->id,
                        'financial_account_id' =>
                            $account->id,
                        'financial_provider_connection_id' =>
                            $connection->id,
                        'requested_by_user_id' =>
                            $actor->id,
                        'amount_minor' =>
                            $amountMinor,
                        'currency_code' =>
                            $locked->currency_code,
                        'idempotency_key' =>
                            $idempotencyKey,
                        'fingerprint' =>
                            $fingerprint,
                        'requested_at' =>
                            $now,
                        'created_at' =>
                            $now,
                    ]);

            $this->audit->record(
                $instruction,
                'commerce_post_sale_external_refund_instructed',
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
                    'original_external_operation_id' =>
                        (string) $payment
                            ->external_operation_id,
                    'financial_account_id' =>
                        (int) $account->id,
                    'financial_provider_connection_id' =>
                        (int) $connection->id,
                    'provider_key' =>
                        $connection->provider_key,
                    'resolved_by_user_id' =>
                        (int) $locked
                            ->resolved_by_user_id,
                    'requested_by_user_id' =>
                        (int) $actor->id,
                    'amount_minor' =>
                        $amountMinor,
                    'currency_code' =>
                        $locked->currency_code,
                ]
            );

            return $instruction->refresh()->load([
                'resolution.request.sale',
                'originalPayment',
                'financialAccount',
                'providerConnection',
                'requestedBy',
            ]);
        }, 3);
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
                'No se pudo construir la huella de la instrucción de reembolso externo.',
                previous: $exception
            );
        }
    }
}
