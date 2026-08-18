<?php

namespace App\Domain\Purchase;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\FinancialMovementDirection;
use App\Enums\FinancialMovementStatus;
use App\Enums\PurchasePaymentDisbursementChannel;
use App\Models\CommercePostSaleExternalRefundEvidence;
use App\Models\FinancialExternalMovement;
use App\Models\PaymentReconciliationAllocation;
use App\Models\PurchasePaymentDisbursement;
use App\Models\PurchasePaymentExternalVerification;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use JsonException;

final class PurchasePaymentExternalVerificationManager
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly AuditRecorder $audit
    ) {
    }

    public function verify(
        PurchasePaymentDisbursement $disbursement,
        FinancialExternalMovement $movement,
        string $idempotencyKey,
        User $actor,
        ?string $note = null
    ): PurchasePaymentExternalVerification {
        $organizationId = $this->organizationId($actor);
        $idempotencyKey = trim($idempotencyKey);
        $note = filled($note)
            ? trim((string) $note)
            : null;

        if (
            $idempotencyKey === ''
            || mb_strlen($idempotencyKey) > 180
        ) {
            throw new DomainException(
                'La clave de idempotencia de verificación externa no es válida.'
            );
        }

        if ($note !== null && mb_strlen($note) > 2000) {
            throw new DomainException(
                'La nota de verificación externa supera la longitud admitida.'
            );
        }

        return DB::transaction(function () use (
            $organizationId,
            $disbursement,
            $movement,
            $idempotencyKey,
            $actor,
            $note
        ): PurchasePaymentExternalVerification {
            $lockedDisbursement =
                PurchasePaymentDisbursement::query()
                    ->forOrganization($organizationId)
                    ->whereKey($disbursement->getKey())
                    ->with([
                        'originFinancialAccount',
                        'externalVerification.financialMovement',
                    ])
                    ->lockForUpdate()
                    ->first();

            if (! $lockedDisbursement) {
                throw new DomainException(
                    'El desembolso no pertenece a la organización activa.'
                );
            }

            if (
                $lockedDisbursement->channel
                    !== PurchasePaymentDisbursementChannel::NonCash
                || blank(
                    $lockedDisbursement->execution_reference
                )
            ) {
                throw new DomainException(
                    'Sólo un desembolso non-cash con referencia admite verificación externa.'
                );
            }

            $lockedMovement = FinancialExternalMovement::query()
                ->forOrganization($organizationId)
                ->whereKey($movement->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedMovement) {
                throw new DomainException(
                    'El movimiento externo no pertenece a la organización activa.'
                );
            }

            $this->assertCompatible(
                $lockedDisbursement,
                $lockedMovement
            );

            $referenceMatchKind = $this->referenceMatchKind(
                (string) $lockedDisbursement
                    ->execution_reference,
                $lockedMovement
            );

            $difference =
                (int) $lockedMovement->gross_amount_minor
                - (int) $lockedDisbursement->amount_minor;

            $needsReason =
                $referenceMatchKind === 'operator_confirmed'
                || $difference !== 0
                || (int) $lockedMovement->fee_amount_minor !== 0
                || (int) $lockedMovement
                    ->withholding_amount_minor !== 0;

            if (
                $needsReason
                && ($note === null || mb_strlen($note) < 10)
            ) {
                throw new DomainException(
                    'La verificación sin referencia exacta o con diferencia requiere una nota de al menos 10 caracteres.'
                );
            }

            $fingerprint = $this->fingerprint([
                'organization_id' => $organizationId,
                'purchase_payment_disbursement_id' =>
                    (int) $lockedDisbursement->id,
                'disbursement_fingerprint' =>
                    (string) $lockedDisbursement->fingerprint,
                'financial_external_movement_id' =>
                    (int) $lockedMovement->id,
                'movement_fingerprint' =>
                    (string) $lockedMovement->fingerprint,
                'reference_match_kind' =>
                    $referenceMatchKind,
                'amount_difference_minor' =>
                    $difference,
                'note' => $note,
            ]);

            $existingByKey =
                PurchasePaymentExternalVerification::query()
                    ->forOrganization($organizationId)
                    ->where(
                        'idempotency_key',
                        $idempotencyKey
                    )
                    ->with([
                        'disbursement',
                        'financialMovement.account',
                        'verifiedBy:id,name',
                    ])
                    ->lockForUpdate()
                    ->first();

            if ($existingByKey) {
                return $this->sameOrFail(
                    $existingByKey,
                    $lockedDisbursement,
                    $lockedMovement,
                    $fingerprint,
                    'La clave de idempotencia ya pertenece a otra verificación externa.'
                );
            }

            $existingForDisbursement =
                PurchasePaymentExternalVerification::query()
                    ->forOrganization($organizationId)
                    ->where(
                        'purchase_payment_disbursement_id',
                        $lockedDisbursement->id
                    )
                    ->with([
                        'disbursement',
                        'financialMovement.account',
                        'verifiedBy:id,name',
                    ])
                    ->lockForUpdate()
                    ->first();

            if ($existingForDisbursement) {
                return $this->sameOrFail(
                    $existingForDisbursement,
                    $lockedDisbursement,
                    $lockedMovement,
                    $fingerprint,
                    'El desembolso ya fue vinculado a otra evidencia externa.'
                );
            }

            $existingForMovement =
                PurchasePaymentExternalVerification::query()
                    ->forOrganization($organizationId)
                    ->where(
                        'financial_external_movement_id',
                        $lockedMovement->id
                    )
                    ->lockForUpdate()
                    ->first();

            if ($existingForMovement) {
                throw new DomainException(
                    'El movimiento externo ya verifica otro desembolso.'
                );
            }

            if (
                PaymentReconciliationAllocation::query()
                    ->forOrganization($organizationId)
                    ->where(
                        'financial_external_movement_id',
                        $lockedMovement->id
                    )
                    ->exists()
                || CommercePostSaleExternalRefundEvidence::query()
                    ->forOrganization($organizationId)
                    ->where(
                        'financial_external_movement_id',
                        $lockedMovement->id
                    )
                    ->exists()
            ) {
                throw new DomainException(
                    'El movimiento externo ya respalda otro hecho financiero.'
                );
            }

            $now = CarbonImmutable::now('UTC');

            $verification =
                PurchasePaymentExternalVerification::query()
                    ->create([
                        'organization_id' =>
                            $organizationId,
                        'purchase_payment_disbursement_id' =>
                            $lockedDisbursement->id,
                        'financial_external_movement_id' =>
                            $lockedMovement->id,
                        'idempotency_key' =>
                            $idempotencyKey,
                        'fingerprint' =>
                            $fingerprint,
                        'reference_match_kind' =>
                            $referenceMatchKind,
                        'amount_difference_minor' =>
                            $difference,
                        'note' => $note,
                        'verified_by_user_id' =>
                            $actor->getKey(),
                        'verified_at' => $now,
                        'created_at' => $now,
                    ]);

            $this->audit->record(
                $verification,
                'purchase_payment_external_verified',
                null,
                [
                    'purchase_payment_disbursement_id' =>
                        (int) $lockedDisbursement->id,
                    'financial_external_movement_id' =>
                        (int) $lockedMovement->id,
                    'origin_financial_account_id' =>
                        (int) $lockedDisbursement
                            ->origin_financial_account_id,
                    'direction' =>
                        $lockedMovement->direction,
                    'status' =>
                        $lockedMovement->status,
                    'currency_code' =>
                        $lockedMovement->currency_code,
                    'expected_amount_minor' =>
                        (int) $lockedDisbursement
                            ->amount_minor,
                    'gross_amount_minor' =>
                        (int) $lockedMovement
                            ->gross_amount_minor,
                    'net_amount_minor' =>
                        (int) $lockedMovement
                            ->net_amount_minor,
                    'fee_amount_minor' =>
                        (int) $lockedMovement
                            ->fee_amount_minor,
                    'withholding_amount_minor' =>
                        (int) $lockedMovement
                            ->withholding_amount_minor,
                    'amount_difference_minor' =>
                        $difference,
                    'reference_match_kind' =>
                        $referenceMatchKind,
                ]
            );

            return $verification
                ->refresh()
                ->load([
                    'disbursement',
                    'financialMovement.account',
                    'verifiedBy:id,name',
                ]);
        }, 3);
    }

    private function assertCompatible(
        PurchasePaymentDisbursement $disbursement,
        FinancialExternalMovement $movement
    ): void {
        if (
            $movement->direction
                !== FinancialMovementDirection::Debit
            || $movement->status
                !== FinancialMovementStatus::Posted
        ) {
            throw new DomainException(
                'Sólo un débito externo contabilizado puede verificar el desembolso.'
            );
        }

        if (
            (int) $movement->financial_account_id
                !== (int) $disbursement
                    ->origin_financial_account_id
            || $movement->currency_code
                !== $disbursement->currency_code
        ) {
            throw new DomainException(
                'Cuenta y moneda externas deben coincidir con el desembolso.'
            );
        }
    }

    private function referenceMatchKind(
        string $reference,
        FinancialExternalMovement $movement
    ): string {
        $reference = trim($reference);

        foreach ([
            'external_operation_id' =>
                $movement->external_operation_id,
            'source_key' =>
                $movement->source_key,
            'raw_reference' =>
                $movement->raw_reference,
        ] as $kind => $candidate) {
            if (
                filled($candidate)
                && hash_equals(
                    $reference,
                    trim((string) $candidate)
                )
            ) {
                return $kind;
            }
        }

        return 'operator_confirmed';
    }

    private function sameOrFail(
        PurchasePaymentExternalVerification $existing,
        PurchasePaymentDisbursement $disbursement,
        FinancialExternalMovement $movement,
        string $fingerprint,
        string $message
    ): PurchasePaymentExternalVerification {
        if (
            (int) $existing
                ->purchase_payment_disbursement_id
                === (int) $disbursement->id
            && (int) $existing
                ->financial_external_movement_id
                === (int) $movement->id
            && hash_equals(
                (string) $existing->fingerprint,
                $fingerprint
            )
        ) {
            return $existing;
        }

        throw new DomainException($message);
    }

    private function organizationId(User $actor): int
    {
        $organizationId =
            $this->currentOrganization->id($actor);
        $role =
            $this->currentOrganization->roleFor($actor);

        if (! ($role?->canReviewFinancialReconciliation() ?? false)) {
            throw new DomainException(
                'No posee permiso para verificar pagos contra evidencia externa.'
            );
        }

        return $organizationId;
    }

    /** @param array<string,mixed> $payload */
    private function fingerprint(array $payload): string
    {
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
                'No se pudo construir la huella de verificación externa.',
                previous: $exception
            );
        }
    }
}
