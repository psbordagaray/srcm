<?php

namespace App\Domain\Finance;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CashMovementDirection;
use App\Enums\CashMovementType;
use App\Enums\CashRegisterSessionStatus;
use App\Enums\CashSecurityDropReason;
use App\Enums\CashSecurityDropRequestStatus;
use App\Enums\FinancialAccountType;
use App\Models\CashMovement;
use App\Models\CashRegisterSession;
use App\Models\CashSecurityDropRequest;
use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CashSecurityDropManager
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly AuditRecorder $audit
    ) {
    }

    public function request(
        FinancialAccount $destination,
        int $amountMinor,
        CashSecurityDropReason $reason,
        ?string $note,
        string $idempotencyKey,
        User $actor
    ): CashSecurityDropRequest {
        $organizationId = $this->organizationIdForRequest($actor);
        $idempotencyKey = $this->idempotencyKey(
            $idempotencyKey,
            'solicitud'
        );
        $note = $this->note($note, 'solicitud');

        if ($amountMinor <= 0) {
            throw new DomainException(
                'El retiro de seguridad debe ser mayor que cero.'
            );
        }

        return DB::transaction(function () use (
            $organizationId,
            $destination,
            $amountMinor,
            $reason,
            $note,
            $idempotencyKey,
            $actor
        ): CashSecurityDropRequest {
            $this->lockOrganization($organizationId);

            $existing = CashSecurityDropRequest::query()
                ->forOrganization($organizationId)
                ->where('request_idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (
                    (int) $existing->requested_by_user_id !== (int) $actor->id
                    || (int) $existing->destination_financial_account_id !==
                        (int) $destination->id
                    || $existing->amount_minor !== $amountMinor
                    || $existing->reason_code !== $reason
                    || $existing->note !== $note
                ) {
                    throw new DomainException(
                        'La misma clave de solicitud fue usada con otros datos.'
                    );
                }

                return $existing;
            }

            $session = CashRegisterSession::query()
                ->forOrganization($organizationId)
                ->where('opened_by_user_id', $actor->id)
                ->where('status', CashRegisterSessionStatus::Open)
                ->lockForUpdate()
                ->first();

            if (! $session) {
                throw new DomainException(
                    'Para solicitar un retiro necesitás un turno abierto propio.'
                );
            }

            if (
                CashSecurityDropRequest::query()
                    ->forOrganization($organizationId)
                    ->where('cash_register_session_id', $session->id)
                    ->whereIn('status', [
                        CashSecurityDropRequestStatus::Pending->value,
                        CashSecurityDropRequestStatus::Approved->value,
                    ])
                    ->lockForUpdate()
                    ->exists()
            ) {
                throw new DomainException(
                    'Ya existe una solicitud de retiro pendiente para este turno.'
                );
            }

            $session->loadMissing('register.financialAccount');
            $register = $session->register;
            $origin = $register?->financialAccount;

            if (
                ! $register
                || ! $register->active
                || (int) $register->organization_id !== $organizationId
                || ! $origin
                || ! $origin->active
                || (int) $origin->organization_id !== $organizationId
                || $origin->type !== FinancialAccountType::CashBox
                || $origin->currency_code !== $session->currency_code
            ) {
                throw new DomainException(
                    'El contexto de caja no es válido para solicitar el retiro.'
                );
            }

            $target = $this->lockDestination(
                $destination,
                $organizationId,
                $session->currency_code,
                $origin->id
            );

            $expectedBefore = $this->lockedExpectedAmountMinor($session);

            if ($amountMinor > $expectedBefore) {
                throw new DomainException(
                    'El retiro solicitado supera el efectivo esperado del turno.'
                );
            }

            $fingerprint = $this->requestFingerprint(
                $organizationId,
                $session->id,
                $register->id,
                $origin->id,
                $target->id,
                $actor->id,
                $amountMinor,
                $session->currency_code,
                $reason,
                $note
            );
            $now = CarbonImmutable::now();

            $request = CashSecurityDropRequest::query()->create([
                'organization_id' => $organizationId,
                'cash_register_session_id' => $session->id,
                'cash_register_id' => $register->id,
                'origin_financial_account_id' => $origin->id,
                'destination_financial_account_id' => $target->id,
                'requested_by_user_id' => $actor->id,
                'amount_minor' => $amountMinor,
                'currency_code' => $session->currency_code,
                'reason_code' => $reason,
                'note' => $note,
                'status' => CashSecurityDropRequestStatus::Pending,
                'request_idempotency_key' => $idempotencyKey,
                'fingerprint' => $fingerprint,
                'requested_at' => $now,
            ]);

            $this->audit->record(
                $request,
                'cash_security_drop_requested',
                null,
                [
                    'cash_register_session_id' => $session->id,
                    'cash_register_id' => $register->id,
                    'origin_financial_account_id' => $origin->id,
                    'destination_financial_account_id' => $target->id,
                    'requested_by_user_id' => $actor->id,
                    'amount_minor' => $amountMinor,
                    'currency_code' => $session->currency_code,
                    'reason_code' => $reason,
                    'expected_at_request_minor' => $expectedBefore,
                ]
            );

            return $request->refresh();
        }, 3);
    }

    public function approve(
        CashSecurityDropRequest $request,
        ?string $approvalNote,
        string $idempotencyKey,
        User $actor
    ): CashSecurityDropRequest {
        $organizationId = $this->organizationIdForApproval($actor);
        $idempotencyKey = $this->idempotencyKey(
            $idempotencyKey,
            'aprobación'
        );
        $approvalNote = $this->note($approvalNote, 'aprobación');

        return DB::transaction(function () use (
            $organizationId,
            $request,
            $approvalNote,
            $idempotencyKey,
            $actor
        ): CashSecurityDropRequest {
            $this->lockOrganization($organizationId);
            $locked = $this->lockRequest($request, $organizationId);

            if (
                in_array($locked->status, [
                    CashSecurityDropRequestStatus::Approved,
                    CashSecurityDropRequestStatus::Executed,
                ], true)
                && $locked->approval_idempotency_key === $idempotencyKey
                && (int) $locked->approved_by_user_id === (int) $actor->id
            ) {
                return $locked;
            }

            if ($locked->status !== CashSecurityDropRequestStatus::Pending) {
                throw new DomainException(
                    'Sólo una solicitud pendiente puede autorizarse.'
                );
            }

            if ((int) $locked->requested_by_user_id === (int) $actor->id) {
                throw new DomainException(
                    'Quien solicita un retiro no puede autorizarlo.'
                );
            }

            $session = $this->lockOpenSessionForRequest($locked);
            $this->lockDestinationForRequest($locked);
            $expectedBefore = $this->lockedExpectedAmountMinor($session);

            if ($locked->amount_minor > $expectedBefore) {
                throw new DomainException(
                    'El retiro ya supera el efectivo esperado actual del turno.'
                );
            }

            $now = CarbonImmutable::now();
            $locked->status = CashSecurityDropRequestStatus::Approved;
            $locked->approved_by_user_id = $actor->id;
            $locked->approved_at = $now;
            $locked->approval_idempotency_key = $idempotencyKey;
            $locked->approval_fingerprint = $this->approvalFingerprint(
                $locked->fingerprint,
                $actor->id
            );
            $locked->approval_note = $approvalNote;
            $locked->save();

            $this->audit->record(
                $locked,
                'cash_security_drop_approved',
                null,
                [
                    'requested_by_user_id' => $locked->requested_by_user_id,
                    'approved_by_user_id' => $actor->id,
                    'amount_minor' => $locked->amount_minor,
                    'currency_code' => $locked->currency_code,
                    'expected_at_approval_minor' => $expectedBefore,
                ]
            );

            return $locked->refresh();
        }, 3);
    }

    public function reject(
        CashSecurityDropRequest $request,
        string $resolutionNote,
        string $idempotencyKey,
        User $actor
    ): CashSecurityDropRequest {
        $organizationId = $this->organizationIdForApproval($actor);

        return $this->resolve(
            $request,
            CashSecurityDropRequestStatus::Rejected,
            $resolutionNote,
            $idempotencyKey,
            $actor,
            $organizationId,
            true
        );
    }

    public function cancel(
        CashSecurityDropRequest $request,
        string $resolutionNote,
        string $idempotencyKey,
        User $actor
    ): CashSecurityDropRequest {
        $organizationId = $this->currentOrganization->id($actor);
        $role = $this->currentOrganization->roleFor($actor);

        return DB::transaction(function () use (
            $organizationId,
            $request,
            $resolutionNote,
            $idempotencyKey,
            $actor,
            $role
        ): CashSecurityDropRequest {
            $this->lockOrganization($organizationId);
            $locked = $this->lockRequest($request, $organizationId);

            if (
                (int) $locked->requested_by_user_id !== (int) $actor->id
                && ! ($role?->canApproveCashSecurityDrop() ?? false)
            ) {
                throw new DomainException(
                    'No posee permiso para cancelar esta solicitud.'
                );
            }

            return $this->resolveLocked(
                $locked,
                CashSecurityDropRequestStatus::Cancelled,
                $resolutionNote,
                $idempotencyKey,
                $actor
            );
        }, 3);
    }

    public function execute(
        CashSecurityDropRequest $request,
        string $idempotencyKey,
        User $actor
    ): CashMovement {
        $organizationId = $this->organizationIdForExecution($actor);
        $idempotencyKey = $this->idempotencyKey(
            $idempotencyKey,
            'ejecución'
        );

        return DB::transaction(function () use (
            $organizationId,
            $request,
            $idempotencyKey,
            $actor
        ): CashMovement {
            $this->lockOrganization($organizationId);
            $locked = $this->lockRequest($request, $organizationId);

            if ($locked->status === CashSecurityDropRequestStatus::Executed) {
                if (
                    $locked->execution_idempotency_key !== $idempotencyKey
                    || (int) $locked->executed_by_user_id !== (int) $actor->id
                ) {
                    throw new DomainException(
                        'El retiro ya fue ejecutado con otra operación.'
                    );
                }

                $movement = CashMovement::query()
                    ->forOrganization($organizationId)
                    ->where('cash_security_drop_request_id', $locked->id)
                    ->first();

                if (! $movement) {
                    throw new DomainException(
                        'La ejecución registrada no posee su movimiento de caja.'
                    );
                }

                return $movement;
            }

            if ($locked->status !== CashSecurityDropRequestStatus::Approved) {
                throw new DomainException(
                    'El retiro debe estar autorizado antes de ejecutarse.'
                );
            }

            if (
                (int) $locked->requested_by_user_id !== (int) $actor->id
                || (int) $locked->approved_by_user_id === (int) $actor->id
            ) {
                throw new DomainException(
                    'El retiro debe ejecutarlo el cajero solicitante autorizado.'
                );
            }

            $expectedApprovalFingerprint = $this->approvalFingerprint(
                $locked->fingerprint,
                (int) $locked->approved_by_user_id
            );

            if (
                blank($locked->approval_fingerprint)
                || ! hash_equals(
                    $locked->approval_fingerprint,
                    $expectedApprovalFingerprint
                )
            ) {
                throw new DomainException(
                    'La autorización del retiro no es válida.'
                );
            }

            $session = $this->lockOpenSessionForRequest($locked);
            $this->lockDestinationForRequest($locked);

            if ((int) $session->opened_by_user_id !== (int) $actor->id) {
                throw new DomainException(
                    'Sólo el responsable del turno puede ejecutar el retiro.'
                );
            }

            $expectedBefore = $this->lockedExpectedAmountMinor($session);

            if ($locked->amount_minor > $expectedBefore) {
                throw new DomainException(
                    'El retiro autorizado supera el efectivo esperado actual.'
                );
            }

            $now = CarbonImmutable::now();
            $movementFingerprint = hash(
                'sha256',
                json_encode([
                    'organization_id' => $organizationId,
                    'cash_security_drop_request_id' => $locked->id,
                    'cash_register_session_id' => $locked->cash_register_session_id,
                    'cash_register_id' => $locked->cash_register_id,
                    'financial_account_id' => $locked->origin_financial_account_id,
                    'destination_financial_account_id' =>
                        $locked->destination_financial_account_id,
                    'direction' => CashMovementDirection::Out->value,
                    'type' => CashMovementType::SecurityDrop->value,
                    'amount_minor' => $locked->amount_minor,
                    'currency_code' => $locked->currency_code,
                    'reason_code' => $locked->reason_code->value,
                    'note' => $locked->note,
                    'recorded_by_user_id' => $actor->id,
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );

            $movement = CashMovement::query()->create([
                'organization_id' => $organizationId,
                'cash_register_session_id' => $locked->cash_register_session_id,
                'cash_register_id' => $locked->cash_register_id,
                'financial_account_id' => $locked->origin_financial_account_id,
                'destination_financial_account_id' =>
                    $locked->destination_financial_account_id,
                'cash_security_drop_request_id' => $locked->id,
                'commerce_payment_id' => null,
                'direction' => CashMovementDirection::Out,
                'type' => CashMovementType::SecurityDrop,
                'reason_code' => $locked->reason_code,
                'note' => $locked->note,
                'amount_minor' => $locked->amount_minor,
                'currency_code' => $locked->currency_code,
                'idempotency_key' => 'security-drop-request:'.$locked->id,
                'fingerprint' => $movementFingerprint,
                'recorded_by_user_id' => $actor->id,
                'occurred_at' => $now,
                'created_at' => $now,
            ]);

            $locked->status = CashSecurityDropRequestStatus::Executed;
            $locked->executed_by_user_id = $actor->id;
            $locked->executed_at = $now;
            $locked->execution_idempotency_key = $idempotencyKey;
            $locked->save();

            $this->audit->record(
                $movement,
                'cash_security_drop_recorded',
                null,
                [
                    'cash_security_drop_request_id' => $locked->id,
                    'cash_register_session_id' => $locked->cash_register_session_id,
                    'cash_register_id' => $locked->cash_register_id,
                    'financial_account_id' =>
                        $locked->origin_financial_account_id,
                    'destination_financial_account_id' =>
                        $locked->destination_financial_account_id,
                    'requested_by_user_id' => $locked->requested_by_user_id,
                    'approved_by_user_id' => $locked->approved_by_user_id,
                    'executed_by_user_id' => $actor->id,
                    'amount_minor' => $locked->amount_minor,
                    'currency_code' => $locked->currency_code,
                    'reason_code' => $locked->reason_code,
                    'expected_before_minor' => $expectedBefore,
                    'expected_after_minor' =>
                        $expectedBefore - $locked->amount_minor,
                ]
            );

            $this->audit->record(
                $locked,
                'cash_security_drop_executed',
                null,
                [
                    'cash_movement_id' => $movement->id,
                    'requested_by_user_id' => $locked->requested_by_user_id,
                    'approved_by_user_id' => $locked->approved_by_user_id,
                    'executed_by_user_id' => $actor->id,
                ]
            );

            return $movement->refresh();
        }, 3);
    }

    private function resolve(
        CashSecurityDropRequest $request,
        CashSecurityDropRequestStatus $status,
        string $resolutionNote,
        string $idempotencyKey,
        User $actor,
        int $organizationId,
        bool $mustBeDifferentFromRequester
    ): CashSecurityDropRequest {
        return DB::transaction(function () use (
            $request,
            $status,
            $resolutionNote,
            $idempotencyKey,
            $actor,
            $organizationId,
            $mustBeDifferentFromRequester
        ): CashSecurityDropRequest {
            $this->lockOrganization($organizationId);
            $locked = $this->lockRequest($request, $organizationId);

            if (
                $mustBeDifferentFromRequester
                && (int) $locked->requested_by_user_id === (int) $actor->id
            ) {
                throw new DomainException(
                    'El solicitante no puede resolver su solicitud como supervisor.'
                );
            }

            return $this->resolveLocked(
                $locked,
                $status,
                $resolutionNote,
                $idempotencyKey,
                $actor
            );
        }, 3);
    }

    private function resolveLocked(
        CashSecurityDropRequest $locked,
        CashSecurityDropRequestStatus $status,
        string $resolutionNote,
        string $idempotencyKey,
        User $actor
    ): CashSecurityDropRequest {
        $idempotencyKey = $this->idempotencyKey(
            $idempotencyKey,
            'resolución'
        );
        $resolutionNote = trim($resolutionNote);

        if ($resolutionNote === '' || mb_strlen($resolutionNote) > 1000) {
            throw new DomainException(
                'La resolución requiere una nota válida.'
            );
        }

        if (
            $locked->status === $status
            && $locked->resolution_idempotency_key === $idempotencyKey
            && (int) $locked->resolved_by_user_id === (int) $actor->id
        ) {
            return $locked;
        }

        if (! in_array($locked->status, [
            CashSecurityDropRequestStatus::Pending,
            CashSecurityDropRequestStatus::Approved,
        ], true)) {
            throw new DomainException(
                'La solicitud ya no admite esa resolución.'
            );
        }

        if (
            $status === CashSecurityDropRequestStatus::Rejected
            && $locked->status !== CashSecurityDropRequestStatus::Pending
        ) {
            throw new DomainException(
                'Una autorización ya otorgada debe cancelarse, no rechazarse.'
            );
        }

        $locked->status = $status;
        $locked->resolved_by_user_id = $actor->id;
        $locked->resolved_at = CarbonImmutable::now();
        $locked->resolution_idempotency_key = $idempotencyKey;
        $locked->resolution_note = $resolutionNote;
        $locked->save();

        $this->audit->record(
            $locked,
            $status === CashSecurityDropRequestStatus::Rejected
                ? 'cash_security_drop_rejected'
                : 'cash_security_drop_cancelled',
            null,
            [
                'status' => $status,
                'resolved_by_user_id' => $actor->id,
                'resolution_note' => $resolutionNote,
            ]
        );

        return $locked->refresh();
    }

    private function lockRequest(
        CashSecurityDropRequest $request,
        int $organizationId
    ): CashSecurityDropRequest {
        $locked = CashSecurityDropRequest::query()
            ->forOrganization($organizationId)
            ->whereKey($request->id)
            ->lockForUpdate()
            ->first();

        if (! $locked) {
            throw new DomainException(
                'La solicitud de retiro no pertenece a la organización activa.'
            );
        }

        return $locked;
    }

    private function lockOpenSessionForRequest(
        CashSecurityDropRequest $request
    ): CashRegisterSession {
        $session = CashRegisterSession::query()
            ->forOrganization($request->organization_id)
            ->whereKey($request->cash_register_session_id)
            ->where('status', CashRegisterSessionStatus::Open)
            ->lockForUpdate()
            ->first();

        if (
            ! $session
            || (int) $session->cash_register_id !==
                (int) $request->cash_register_id
            || (int) $session->opened_by_user_id !==
                (int) $request->requested_by_user_id
            || $session->currency_code !== $request->currency_code
        ) {
            throw new DomainException(
                'El turno asociado al retiro ya no está disponible.'
            );
        }

        $session->loadMissing('register.financialAccount');
        $register = $session->register;
        $origin = $register?->financialAccount;

        if (
            ! $register
            || ! $register->active
            || (int) $register->id !== (int) $request->cash_register_id
            || ! $origin
            || ! $origin->active
            || $origin->type !== FinancialAccountType::CashBox
            || (int) $origin->id !==
                (int) $request->origin_financial_account_id
            || $origin->currency_code !== $request->currency_code
        ) {
            throw new DomainException(
                'La caja origen del retiro ya no está disponible.'
            );
        }

        return $session;
    }

    private function lockDestinationForRequest(
        CashSecurityDropRequest $request
    ): FinancialAccount {
        $destination = FinancialAccount::query()
            ->forOrganization($request->organization_id)
            ->whereKey($request->destination_financial_account_id)
            ->where('active', true)
            ->lockForUpdate()
            ->first();

        if (
            ! $destination
            || $destination->type !== FinancialAccountType::CashReserve
            || $destination->currency_code !== $request->currency_code
            || (int) $destination->id ===
                (int) $request->origin_financial_account_id
        ) {
            throw new DomainException(
                'El destino autorizado ya no está disponible.'
            );
        }

        return $destination;
    }

    private function lockDestination(
        FinancialAccount $destination,
        int $organizationId,
        string $currencyCode,
        int $originId
    ): FinancialAccount {
        $target = FinancialAccount::query()
            ->forOrganization($organizationId)
            ->whereKey($destination->id)
            ->where('active', true)
            ->lockForUpdate()
            ->first();

        if (
            ! $target
            || $target->type !== FinancialAccountType::CashReserve
            || $target->currency_code !== $currencyCode
            || (int) $target->id === $originId
        ) {
            throw new DomainException(
                'El destino debe ser una reserva activa de la misma moneda.'
            );
        }

        return $target;
    }

    private function lockedExpectedAmountMinor(
        CashRegisterSession $session
    ): int {
        $netMinor = (int) CashMovement::query()
            ->where('cash_register_session_id', $session->id)
            ->lockForUpdate()
            ->get(['direction', 'amount_minor'])
            ->sum(
                fn (CashMovement $movement): int =>
                    $movement->direction === CashMovementDirection::In
                        ? $movement->amount_minor
                        : -$movement->amount_minor
            );

        return $session->opening_amount_minor + $netMinor;
    }

    private function organizationIdForRequest(User $actor): int
    {
        $organizationId = $this->currentOrganization->id($actor);
        $role = $this->currentOrganization->roleFor($actor);

        if (! ($role?->canRequestCashSecurityDrop() ?? false)) {
            throw new DomainException(
                'No posee permiso para solicitar retiros de seguridad.'
            );
        }

        return $organizationId;
    }

    private function organizationIdForApproval(User $actor): int
    {
        $organizationId = $this->currentOrganization->id($actor);
        $role = $this->currentOrganization->roleFor($actor);

        if (! ($role?->canApproveCashSecurityDrop() ?? false)) {
            throw new DomainException(
                'No posee permiso para autorizar retiros de seguridad.'
            );
        }

        return $organizationId;
    }

    private function organizationIdForExecution(User $actor): int
    {
        $organizationId = $this->currentOrganization->id($actor);
        $role = $this->currentOrganization->roleFor($actor);

        if (! ($role?->canExecuteCashSecurityDrop() ?? false)) {
            throw new DomainException(
                'No posee permiso para ejecutar retiros de seguridad.'
            );
        }

        return $organizationId;
    }

    private function lockOrganization(int $organizationId): void
    {
        $exists = Organization::query()
            ->whereKey($organizationId)
            ->where('active', true)
            ->lockForUpdate()
            ->exists();

        if (! $exists) {
            throw new DomainException('La organización no está activa.');
        }
    }

    private function idempotencyKey(string $value, string $label): string
    {
        $value = Str::of($value)->squish()->toString();

        if ($value === '' || mb_strlen($value) > 191) {
            throw new DomainException(
                'La clave de idempotencia de '.$label.' no es válida.'
            );
        }

        return $value;
    }

    private function note(?string $value, string $label): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > 1000) {
            throw new DomainException(
                'La nota de '.$label.' es demasiado extensa.'
            );
        }

        return $value;
    }

    private function requestFingerprint(
        int $organizationId,
        int $sessionId,
        int $registerId,
        int $originId,
        int $destinationId,
        int $requestedByUserId,
        int $amountMinor,
        string $currencyCode,
        CashSecurityDropReason $reason,
        ?string $note
    ): string {
        return hash('sha256', json_encode([
            'organization_id' => $organizationId,
            'cash_register_session_id' => $sessionId,
            'cash_register_id' => $registerId,
            'origin_financial_account_id' => $originId,
            'destination_financial_account_id' => $destinationId,
            'requested_by_user_id' => $requestedByUserId,
            'amount_minor' => $amountMinor,
            'currency_code' => $currencyCode,
            'reason_code' => $reason->value,
            'note' => $note,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function approvalFingerprint(
        string $requestFingerprint,
        int $approvedByUserId
    ): string {
        return hash('sha256', json_encode([
            'request_fingerprint' => $requestFingerprint,
            'approved_by_user_id' => $approvedByUserId,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
