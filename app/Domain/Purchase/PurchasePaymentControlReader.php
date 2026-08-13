<?php

namespace App\Domain\Purchase;

use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CashMovementDirection;
use App\Enums\CashMovementType;
use App\Enums\FinancialAccountType;
use App\Models\PurchasePaymentExecution;
use App\Models\User;
use DomainException;

final class PurchasePaymentControlReader
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization
    ) {
    }

    /**
     * Read-only control projection.
     *
     * @return array{
     *   state: string,
     *   severity: string,
     *   title: string,
     *   detail: string,
     *   reconciliation_mode: string,
     *   external_verification_applicable: bool,
     *   difference_minor: int|null,
     *   occurred_at: mixed
     * }
     */
    public function read(
        PurchasePaymentExecution $execution,
        User $actor
    ): array {
        $organizationId = $this->currentOrganization->id($actor);

        $execution = PurchasePaymentExecution::query()
            ->forOrganization($organizationId)
            ->whereKey($execution->getKey())
            ->with([
                'originFinancialAccount',
                'cashMovement',
                'cashRegisterSession.closure',
                'cashRegister',
                'executedBy:id,name',
            ])
            ->first();

        if (! $execution) {
            throw new DomainException(
                'La ejecución de pago no pertenece a la organización activa.'
            );
        }

        $origin = $execution->originFinancialAccount;

        if (! $origin) {
            return $this->anomaly(
                $execution,
                'La ejecución no conserva una cuenta de origen disponible.'
            );
        }

        if ($origin->type !== FinancialAccountType::CashBox) {
            return [
                'state' => 'external_verification_pending',
                'severity' => 'warning',
                'title' =>
                    'Débito externo pendiente de verificación',
                'detail' =>
                    'El pago saliente está ejecutado, pero la entidad '.
                    'financiera todavía debe aportar un movimiento externo '.
                    'verificado antes de cualquier conciliación.',
                'reconciliation_mode' =>
                    'external_financial_movement',
                'external_verification_applicable' => true,
                'difference_minor' => null,
                'occurred_at' => $execution->executed_at,
            ];
        }

        $movement = $execution->cashMovement;
        $session = $execution->cashRegisterSession;
        $register = $execution->cashRegister;

        if (
            ! $movement
            || ! $session
            || ! $register
            || (int) $movement->organization_id
                !== (int) $execution->organization_id
            || (int) $movement->purchase_payment_execution_id
                !== (int) $execution->id
            || (int) $movement->cash_register_session_id
                !== (int) $execution->cash_register_session_id
            || (int) $movement->cash_register_id
                !== (int) $execution->cash_register_id
            || (int) $movement->financial_account_id
                !== (int) $execution->origin_financial_account_id
            || (int) $movement->recorded_by_user_id
                !== (int) $execution->executed_by_user_id
            || $movement->direction
                !== CashMovementDirection::Out
            || $movement->type
                !== CashMovementType::PurchasePayment
            || (int) $movement->amount_minor
                !== (int) $execution->amount_minor
            || $movement->currency_code
                !== $execution->currency_code
            || $movement->destination_financial_account_id !== null
            || $movement->cash_security_drop_request_id !== null
            || $movement->commerce_payment_id !== null
            || (int) $session->organization_id
                !== (int) $execution->organization_id
            || (int) $session->id
                !== (int) $execution->cash_register_session_id
            || (int) $register->organization_id
                !== (int) $execution->organization_id
            || (int) $register->id
                !== (int) $execution->cash_register_id
            || (int) $register->financial_account_id
                !== (int) $execution->origin_financial_account_id
        ) {
            return $this->anomaly(
                $execution,
                'La ejecución y el egreso de Caja no conservan el mismo '.
                'hecho monetario estructurado.'
            );
        }

        $closure = $session->closure;

        if (! $closure) {
            return [
                'state' => 'cash_recorded_pending_count',
                'severity' => 'success',
                'title' =>
                    'Caja registrada · control físico pendiente',
                'detail' =>
                    'El egreso de '.
                    $this->money(
                        $execution->amount_minor,
                        $execution->currency_code
                    ).
                    ' está registrado en Caja. El arqueo/cierre del turno '.
                    'controlará el efectivo físico. Este pago en efectivo '.
                    'no crea movimiento externo ni conciliación financiera.',
                'reconciliation_mode' =>
                    'cash_register_closure',
                'external_verification_applicable' => false,
                'difference_minor' => null,
                'occurred_at' => $execution->executed_at,
            ];
        }

        if ((int) $closure->difference_minor === 0) {
            return [
                'state' => 'cash_counted_exact',
                'severity' => 'success',
                'title' =>
                    'Caja controlada · turno cerrado sin diferencia',
                'detail' =>
                    'El egreso ejecutado permanece inmutable y el cierre '.
                    'del turno confirmó el efectivo esperado sin diferencia. '.
                    'No corresponde conciliación externa para efectivo.',
                'reconciliation_mode' =>
                    'cash_register_closure',
                'external_verification_applicable' => false,
                'difference_minor' => 0,
                'occurred_at' => $closure->closed_at,
            ];
        }

        return [
            'state' => 'cash_counted_difference',
            'severity' => 'warning',
            'title' =>
                'Caja cerrada con diferencia · revisar control',
            'detail' =>
                'El turno cerró con una diferencia de '.
                $this->signedMoney(
                    (int) $closure->difference_minor,
                    $closure->currency_code
                ).
                '. La diferencia no reescribe, compensa ni vuelve a pagar '.
                'la ejecución confirmada; debe revisarse como hecho de Caja.',
            'reconciliation_mode' =>
                'cash_register_closure',
            'external_verification_applicable' => false,
            'difference_minor' =>
                (int) $closure->difference_minor,
            'occurred_at' => $closure->closed_at,
        ];
    }

    /**
     * @return array{
     *   state: string,
     *   severity: string,
     *   title: string,
     *   detail: string,
     *   reconciliation_mode: string,
     *   external_verification_applicable: bool,
     *   difference_minor: int|null,
     *   occurred_at: mixed
     * }
     */
    private function anomaly(
        PurchasePaymentExecution $execution,
        string $detail
    ): array {
        return [
            'state' => 'integrity_anomaly',
            'severity' => 'danger',
            'title' => 'Anomalía de control · revisar evidencia',
            'detail' =>
                $detail.
                ' SRCM no corrige ni inventa movimientos para cuadrarla.',
            'reconciliation_mode' => 'control_exception',
            'external_verification_applicable' => false,
            'difference_minor' => null,
            'occurred_at' => $execution->executed_at,
        ];
    }

    private function money(int $minor, string $currency): string
    {
        return $currency.' '.number_format(
            $minor / 100,
            2,
            ',',
            '.'
        );
    }

    private function signedMoney(int $minor, string $currency): string
    {
        $sign = $minor > 0 ? '+' : ($minor < 0 ? '-' : '');

        return $sign.$this->money(abs($minor), $currency);
    }
}
