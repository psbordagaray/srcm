<?php

namespace App\Domain\Purchase;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\FinancialMovementDirection;
use App\Enums\FinancialMovementStatus;
use App\Enums\PurchasePaymentExternalResolutionOutcome;
use App\Models\FinancialExternalMovement;
use App\Models\PurchasePaymentExternalResolution;
use App\Models\PurchasePaymentExternalVerification;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use JsonException;

final class PurchasePaymentExternalResolutionManager
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly AuditRecorder $audit
    ) {
    }

    public function resolve(
        PurchasePaymentExternalVerification $verification,
        PurchasePaymentExternalResolutionOutcome $outcome,
        string $idempotencyKey,
        User $actor,
        string $note
    ): PurchasePaymentExternalResolution {
        $organizationId = $this->organizationId($actor);
        $idempotencyKey = trim($idempotencyKey);
        $note = trim($note);

        if (
            $idempotencyKey === ''
            || mb_strlen($idempotencyKey) > 180
        ) {
            throw new DomainException(
                'La clave de idempotencia de resolución externa no es válida.'
            );
        }

        if (
            mb_strlen($note) < 10
            || mb_strlen($note) > 2000
        ) {
            throw new DomainException(
                'La resolución externa requiere una nota de 10 a 2000 caracteres.'
            );
        }

        return DB::transaction(function () use (
            $organizationId,
            $verification,
            $outcome,
            $idempotencyKey,
            $actor,
            $note
        ): PurchasePaymentExternalResolution {
            $lockedVerification =
                PurchasePaymentExternalVerification::query()
                    ->forOrganization($organizationId)
                    ->whereKey($verification->getKey())
                    ->with([
                        'disbursement',
                        'financialMovement',
                    ])
                    ->lockForUpdate()
                    ->first();

            if (! $lockedVerification) {
                throw new DomainException(
                    'La verificación externa no pertenece a la organización activa.'
                );
            }

            $verifiedMovement =
                $lockedVerification->financialMovement;

            if (! $verifiedMovement) {
                throw new DomainException(
                    'La verificación externa no conserva su movimiento contabilizado.'
                );
            }

            $reviewedMovement = $this->latestMovement(
                $organizationId,
                $verifiedMovement
            );

            $difference = (int) $reviewedMovement
                ->gross_amount_minor
                - (int) $lockedVerification
                    ->disbursement->amount_minor;
            $fee = (int) $reviewedMovement
                ->fee_amount_minor;
            $withholding = (int) $reviewedMovement
                ->withholding_amount_minor;

            if (
                $reviewedMovement->status
                    === FinancialMovementStatus::Posted
                && $difference === 0
                && $fee === 0
                && $withholding === 0
            ) {
                throw new DomainException(
                    'La verificación exacta y contabilizada no posee una diferencia que resolver.'
                );
            }

            if (
                $outcome
                    === PurchasePaymentExternalResolutionOutcome::TreasuryExceptionAccepted
                && $reviewedMovement->status
                    !== FinancialMovementStatus::Posted
            ) {
                throw new DomainException(
                    'Una observación pendiente, fallida o revertida exige seguimiento y no puede aceptarse como excepción cerrada.'
                );
            }

            $fingerprint = $this->fingerprint([
                'organization_id' => $organizationId,
                'purchase_payment_external_verification_id' =>
                    (int) $lockedVerification->id,
                'verification_fingerprint' =>
                    (string) $lockedVerification->fingerprint,
                'reviewed_financial_external_movement_id' =>
                    (int) $reviewedMovement->id,
                'reviewed_movement_fingerprint' =>
                    (string) $reviewedMovement->fingerprint,
                'outcome' => $outcome->value,
                'observed_status' =>
                    $reviewedMovement->status->value,
                'amount_difference_minor' => $difference,
                'fee_amount_minor' => $fee,
                'withholding_amount_minor' => $withholding,
                'note' => $note,
            ]);

            $existingByKey =
                PurchasePaymentExternalResolution::query()
                    ->forOrganization($organizationId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->with($this->relations())
                    ->lockForUpdate()
                    ->first();

            if ($existingByKey) {
                return $this->sameOrFail(
                    $existingByKey,
                    $lockedVerification,
                    $reviewedMovement,
                    $fingerprint,
                    'La clave de idempotencia ya pertenece a otra resolución externa.'
                );
            }

            $existingForObservation =
                PurchasePaymentExternalResolution::query()
                    ->forOrganization($organizationId)
                    ->where(
                        'purchase_payment_external_verification_id',
                        $lockedVerification->id
                    )
                    ->where(
                        'reviewed_financial_external_movement_id',
                        $reviewedMovement->id
                    )
                    ->with($this->relations())
                    ->lockForUpdate()
                    ->first();

            if ($existingForObservation) {
                return $this->sameOrFail(
                    $existingForObservation,
                    $lockedVerification,
                    $reviewedMovement,
                    $fingerprint,
                    'La observación externa ya fue resuelta con otra decisión.'
                );
            }

            $now = CarbonImmutable::now('UTC');

            $resolution =
                PurchasePaymentExternalResolution::query()
                    ->create([
                        'organization_id' => $organizationId,
                        'purchase_payment_external_verification_id' =>
                            $lockedVerification->id,
                        'reviewed_financial_external_movement_id' =>
                            $reviewedMovement->id,
                        'idempotency_key' => $idempotencyKey,
                        'fingerprint' => $fingerprint,
                        'outcome' => $outcome,
                        'observed_status' =>
                            $reviewedMovement->status,
                        'amount_difference_minor' => $difference,
                        'fee_amount_minor' => $fee,
                        'withholding_amount_minor' => $withholding,
                        'note' => $note,
                        'resolved_by_user_id' =>
                            $actor->getKey(),
                        'resolved_at' => $now,
                        'created_at' => $now,
                    ]);

            $this->audit->record(
                $resolution,
                'purchase_payment_external_resolution_recorded',
                null,
                [
                    'purchase_payment_external_verification_id' =>
                        (int) $lockedVerification->id,
                    'purchase_payment_disbursement_id' =>
                        (int) $lockedVerification
                            ->purchase_payment_disbursement_id,
                    'reviewed_financial_external_movement_id' =>
                        (int) $reviewedMovement->id,
                    'outcome' => $outcome->value,
                    'observed_status' =>
                        $reviewedMovement->status->value,
                    'amount_difference_minor' => $difference,
                    'fee_amount_minor' => $fee,
                    'withholding_amount_minor' => $withholding,
                    'affects_purchase_obligation' => false,
                    'creates_accounting_fact' => false,
                ]
            );

            return $resolution
                ->refresh()
                ->load($this->relations());
        }, 3);
    }

    private function latestMovement(
        int $organizationId,
        FinancialExternalMovement $verifiedMovement
    ): FinancialExternalMovement {
        if (blank($verifiedMovement->external_operation_id)) {
            return $verifiedMovement;
        }

        return FinancialExternalMovement::query()
            ->forOrganization($organizationId)
            ->where(
                'financial_account_id',
                $verifiedMovement->financial_account_id
            )
            ->where(
                'external_operation_id',
                $verifiedMovement->external_operation_id
            )
            ->where(
                'direction',
                FinancialMovementDirection::Debit->value
            )
            ->where(
                'currency_code',
                $verifiedMovement->currency_code
            )
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first()
            ?? $verifiedMovement;
    }

    private function sameOrFail(
        PurchasePaymentExternalResolution $existing,
        PurchasePaymentExternalVerification $verification,
        FinancialExternalMovement $reviewedMovement,
        string $fingerprint,
        string $message
    ): PurchasePaymentExternalResolution {
        if (
            (int) $existing
                ->purchase_payment_external_verification_id
                === (int) $verification->id
            && (int) $existing
                ->reviewed_financial_external_movement_id
                === (int) $reviewedMovement->id
            && hash_equals(
                (string) $existing->fingerprint,
                $fingerprint
            )
        ) {
            return $existing;
        }

        throw new DomainException($message);
    }

    /** @return list<string> */
    private function relations(): array
    {
        return [
            'verification.disbursement',
            'verification.financialMovement.account',
            'reviewedMovement',
            'resolvedBy:id,name',
        ];
    }

    private function organizationId(User $actor): int
    {
        $organizationId =
            $this->currentOrganization->id($actor);
        $role =
            $this->currentOrganization->roleFor($actor);

        if (! ($role?->canReviewFinancialReconciliation() ?? false)) {
            throw new DomainException(
                'No posee permiso para resolver diferencias externas de pagos.'
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
                'No se pudo construir la huella de resolución externa.',
                previous: $exception
            );
        }
    }
}
