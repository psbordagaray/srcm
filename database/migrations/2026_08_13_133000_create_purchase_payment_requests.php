<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INSERT_TRIGGER =
        'purchase_payment_requests_guard_insert';

    private const UPDATE_TRIGGER =
        'purchase_payment_requests_guard_update';

    private const DELETE_TRIGGER =
        'purchase_payment_requests_guard_delete';

    public function up(): void
    {
        Schema::create(
            'purchase_payment_requests',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->uuid('public_id')->unique();
                $table->foreignId('purchase_obligation_id')
                    ->constrained('purchase_obligations')
                    ->restrictOnDelete();
                $table->foreignId(
                    'origin_financial_account_id'
                )
                    ->constrained('financial_accounts')
                    ->restrictOnDelete();
                $table->foreignId(
                    'beneficiary_business_party_id'
                )
                    ->constrained('business_parties')
                    ->restrictOnDelete();
                $table->foreignId('requested_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->foreignId('approved_by_user_id')
                    ->nullable()
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->foreignId('resolved_by_user_id')
                    ->nullable()
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->unsignedBigInteger('amount_minor');
                $table->char('currency_code', 3);
                $table->string('status', 32);
                $table->text('request_note')->nullable();
                $table->string(
                    'request_idempotency_key',
                    180
                );
                $table->char('fingerprint', 64);
                $table->string(
                    'approval_idempotency_key',
                    180
                )->nullable();
                $table->char(
                    'approval_fingerprint',
                    64
                )->nullable();
                $table->text('approval_note')->nullable();
                $table->string(
                    'resolution_idempotency_key',
                    180
                )->nullable();
                $table->text('resolution_note')->nullable();
                $table->timestamp('requested_at');
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamp('created_at');

                $table->unique(
                    [
                        'organization_id',
                        'request_idempotency_key',
                    ],
                    'purchase_pay_req_org_idem_unique'
                );
                $table->index(
                    [
                        'organization_id',
                        'purchase_obligation_id',
                        'status',
                    ],
                    'purchase_pay_req_obligation_status_idx'
                );
                $table->index(
                    [
                        'organization_id',
                        'requested_by_user_id',
                        'status',
                    ],
                    'purchase_pay_req_requester_status_idx'
                );
            }
        );

        $this->createTriggers();
    }

    public function down(): void
    {
        $this->dropTriggers();
        Schema::dropIfExists('purchase_payment_requests');
    }

    private function createTriggers(): void
    {
        $this->dropTriggers();

        if (DB::getDriverName() === 'sqlite') {
            $this->createSqliteTriggers();

            return;
        }

        if (in_array(
            DB::getDriverName(),
            ['mysql', 'mariadb'],
            true
        )) {
            $this->createMysqlTriggers();

            return;
        }

        throw new LogicException(
            'La integridad P4F.2 no está implementada para '
            .DB::getDriverName().'.'
        );
    }

    private function dropTriggers(): void
    {
        foreach ([
            self::INSERT_TRIGGER,
            self::UPDATE_TRIGGER,
            self::DELETE_TRIGGER,
        ] as $trigger) {
            DB::unprepared(
                'DROP TRIGGER IF EXISTS '.$trigger
            );
        }
    }

    private function createSqliteTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_payment_requests_guard_insert
BEFORE INSERT ON purchase_payment_requests
WHEN NEW.amount_minor <= 0
    OR LENGTH(NEW.currency_code) <> 3
    OR NEW.currency_code <> UPPER(NEW.currency_code)
    OR NEW.status <> 'pending'
    OR LENGTH(NEW.fingerprint) <> 64
    OR LENGTH(TRIM(NEW.request_idempotency_key)) = 0
    OR NEW.requested_at IS NULL
    OR NEW.created_at IS NULL
    OR NEW.approved_by_user_id IS NOT NULL
    OR NEW.resolved_by_user_id IS NOT NULL
    OR NEW.approval_idempotency_key IS NOT NULL
    OR NEW.approval_fingerprint IS NOT NULL
    OR NEW.approval_note IS NOT NULL
    OR NEW.resolution_idempotency_key IS NOT NULL
    OR NEW.resolution_note IS NOT NULL
    OR NEW.approved_at IS NOT NULL
    OR NEW.resolved_at IS NOT NULL
    OR NOT EXISTS (
        SELECT 1
        FROM purchase_obligations obligation
        WHERE obligation.id = NEW.purchase_obligation_id
          AND obligation.organization_id =
              NEW.organization_id
          AND obligation.beneficiary_business_party_id =
              NEW.beneficiary_business_party_id
          AND obligation.currency_code =
              NEW.currency_code
          AND NEW.amount_minor <= obligation.amount_minor
    )
    OR NOT EXISTS (
        SELECT 1
        FROM financial_accounts account
        WHERE account.id =
            NEW.origin_financial_account_id
          AND account.organization_id =
            NEW.organization_id
          AND account.active = 1
          AND account.currency_code =
            NEW.currency_code
    )
    OR NOT EXISTS (
        SELECT 1
        FROM business_parties party
        WHERE party.id =
            NEW.beneficiary_business_party_id
          AND party.organization_id =
            NEW.organization_id
    )
    OR NOT EXISTS (
        SELECT 1
        FROM organization_memberships membership
        WHERE membership.organization_id =
            NEW.organization_id
          AND membership.user_id =
            NEW.requested_by_user_id
          AND membership.active = 1
          AND membership.role IN ('admin', 'operator')
    )
    OR EXISTS (
        SELECT 1
        FROM purchase_payment_requests active_request
        WHERE active_request.organization_id =
            NEW.organization_id
          AND active_request.purchase_obligation_id =
            NEW.purchase_obligation_id
          AND active_request.status IN (
              'pending',
              'approved'
          )
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'La solicitud de pago no conserva obligación, origen, importe, autoridad o estado válidos.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_payment_requests_guard_update
BEFORE UPDATE ON purchase_payment_requests
WHEN NEW.organization_id <> OLD.organization_id
    OR NEW.public_id <> OLD.public_id
    OR NEW.purchase_obligation_id <>
        OLD.purchase_obligation_id
    OR NEW.origin_financial_account_id <>
        OLD.origin_financial_account_id
    OR NEW.beneficiary_business_party_id <>
        OLD.beneficiary_business_party_id
    OR NEW.requested_by_user_id <>
        OLD.requested_by_user_id
    OR NEW.amount_minor <> OLD.amount_minor
    OR NEW.currency_code <> OLD.currency_code
    OR COALESCE(NEW.request_note, '') <>
        COALESCE(OLD.request_note, '')
    OR NEW.request_idempotency_key <>
        OLD.request_idempotency_key
    OR NEW.fingerprint <> OLD.fingerprint
    OR NEW.requested_at <> OLD.requested_at
    OR NEW.created_at <> OLD.created_at
    OR OLD.status IN (
        'rejected',
        'cancelled',
        'expired'
    )
    OR NOT (
        (
            OLD.status = 'pending'
            AND NEW.status = 'approved'
            AND NEW.approved_by_user_id IS NOT NULL
            AND NEW.approved_by_user_id <>
                NEW.requested_by_user_id
            AND NEW.approval_idempotency_key IS NOT NULL
            AND LENGTH(
                TRIM(NEW.approval_idempotency_key)
            ) > 0
            AND NEW.approval_fingerprint IS NOT NULL
            AND LENGTH(NEW.approval_fingerprint) = 64
            AND NEW.approved_at IS NOT NULL
            AND NEW.resolved_by_user_id IS NULL
            AND NEW.resolution_idempotency_key IS NULL
            AND NEW.resolution_note IS NULL
            AND NEW.resolved_at IS NULL
            AND EXISTS (
                SELECT 1
                FROM organization_memberships membership
                WHERE membership.organization_id =
                    NEW.organization_id
                  AND membership.user_id =
                    NEW.approved_by_user_id
                  AND membership.active = 1
                  AND membership.role = 'admin'
            )
            AND EXISTS (
                SELECT 1
                FROM financial_accounts account
                WHERE account.id =
                    NEW.origin_financial_account_id
                  AND account.organization_id =
                    NEW.organization_id
                  AND account.active = 1
                  AND account.currency_code =
                    NEW.currency_code
            )
        )
        OR (
            OLD.status = 'pending'
            AND NEW.status IN (
                'rejected',
                'cancelled',
                'expired'
            )
            AND NEW.approved_by_user_id IS NULL
            AND NEW.approval_idempotency_key IS NULL
            AND NEW.approval_fingerprint IS NULL
            AND NEW.approval_note IS NULL
            AND NEW.approved_at IS NULL
            AND NEW.resolved_by_user_id IS NOT NULL
            AND NEW.resolution_idempotency_key IS NOT NULL
            AND LENGTH(
                TRIM(NEW.resolution_idempotency_key)
            ) > 0
            AND NEW.resolution_note IS NOT NULL
            AND LENGTH(TRIM(NEW.resolution_note)) > 0
            AND NEW.resolved_at IS NOT NULL
            AND (
                (
                    NEW.status = 'cancelled'
                    AND NEW.resolved_by_user_id =
                        NEW.requested_by_user_id
                )
                OR EXISTS (
                    SELECT 1
                    FROM organization_memberships membership
                    WHERE membership.organization_id =
                        NEW.organization_id
                      AND membership.user_id =
                        NEW.resolved_by_user_id
                      AND membership.active = 1
                      AND membership.role = 'admin'
                      AND (
                          NEW.status = 'cancelled'
                          OR NEW.resolved_by_user_id <>
                              NEW.requested_by_user_id
                      )
                )
            )
        )
        OR (
            OLD.status = 'approved'
            AND NEW.status IN (
                'cancelled',
                'expired'
            )
            AND NEW.approved_by_user_id =
                OLD.approved_by_user_id
            AND NEW.approval_idempotency_key =
                OLD.approval_idempotency_key
            AND NEW.approval_fingerprint =
                OLD.approval_fingerprint
            AND COALESCE(NEW.approval_note, '') =
                COALESCE(OLD.approval_note, '')
            AND NEW.approved_at = OLD.approved_at
            AND NEW.resolved_by_user_id IS NOT NULL
            AND NEW.resolution_idempotency_key IS NOT NULL
            AND LENGTH(
                TRIM(NEW.resolution_idempotency_key)
            ) > 0
            AND NEW.resolution_note IS NOT NULL
            AND LENGTH(TRIM(NEW.resolution_note)) > 0
            AND NEW.resolved_at IS NOT NULL
            AND (
                (
                    NEW.status = 'cancelled'
                    AND NEW.resolved_by_user_id =
                        NEW.requested_by_user_id
                )
                OR EXISTS (
                    SELECT 1
                    FROM organization_memberships membership
                    WHERE membership.organization_id =
                        NEW.organization_id
                      AND membership.user_id =
                        NEW.resolved_by_user_id
                      AND membership.active = 1
                      AND membership.role = 'admin'
                      AND (
                          NEW.status = 'cancelled'
                          OR NEW.resolved_by_user_id <>
                              NEW.requested_by_user_id
                      )
                )
            )
        )
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'Transición o mutación inválida de solicitud de pago.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_payment_requests_guard_delete
BEFORE DELETE ON purchase_payment_requests
BEGIN
    SELECT RAISE(
        ABORT,
        'Una solicitud de pago no puede eliminarse.'
    );
END
SQL);
    }

    private function createMysqlTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_payment_requests_guard_insert
BEFORE INSERT ON purchase_payment_requests
FOR EACH ROW
BEGIN
    IF NEW.amount_minor <= 0
        OR CHAR_LENGTH(NEW.currency_code) <> 3
        OR NEW.currency_code <> UPPER(NEW.currency_code)
        OR NEW.status <> 'pending'
        OR CHAR_LENGTH(NEW.fingerprint) <> 64
        OR CHAR_LENGTH(TRIM(NEW.request_idempotency_key)) = 0
        OR NEW.requested_at IS NULL
        OR NEW.created_at IS NULL
        OR NEW.approved_by_user_id IS NOT NULL
        OR NEW.resolved_by_user_id IS NOT NULL
        OR NEW.approval_idempotency_key IS NOT NULL
        OR NEW.approval_fingerprint IS NOT NULL
        OR NEW.approval_note IS NOT NULL
        OR NEW.resolution_idempotency_key IS NOT NULL
        OR NEW.resolution_note IS NOT NULL
        OR NEW.approved_at IS NOT NULL
        OR NEW.resolved_at IS NOT NULL
        OR NOT EXISTS (
            SELECT 1
            FROM purchase_obligations obligation
            WHERE obligation.id =
                NEW.purchase_obligation_id
              AND obligation.organization_id =
                  NEW.organization_id
              AND obligation.beneficiary_business_party_id =
                  NEW.beneficiary_business_party_id
              AND obligation.currency_code =
                  NEW.currency_code
              AND NEW.amount_minor <=
                  obligation.amount_minor
        )
        OR NOT EXISTS (
            SELECT 1
            FROM financial_accounts account
            WHERE account.id =
                NEW.origin_financial_account_id
              AND account.organization_id =
                  NEW.organization_id
              AND account.active = 1
              AND account.currency_code =
                  NEW.currency_code
        )
        OR NOT EXISTS (
            SELECT 1
            FROM business_parties party
            WHERE party.id =
                NEW.beneficiary_business_party_id
              AND party.organization_id =
                  NEW.organization_id
        )
        OR NOT EXISTS (
            SELECT 1
            FROM organization_memberships membership
            WHERE membership.organization_id =
                NEW.organization_id
              AND membership.user_id =
                NEW.requested_by_user_id
              AND membership.active = 1
              AND membership.role IN (
                  'admin',
                  'operator'
              )
        )
        OR EXISTS (
            SELECT 1
            FROM purchase_payment_requests active_request
            WHERE active_request.organization_id =
                NEW.organization_id
              AND active_request.purchase_obligation_id =
                NEW.purchase_obligation_id
              AND active_request.status IN (
                  'pending',
                  'approved'
              )
        )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'La solicitud de pago no conserva obligación, origen, importe, autoridad o estado válidos.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_payment_requests_guard_update
BEFORE UPDATE ON purchase_payment_requests
FOR EACH ROW
BEGIN
    IF NEW.organization_id <> OLD.organization_id
        OR NEW.public_id <> OLD.public_id
        OR NEW.purchase_obligation_id <>
            OLD.purchase_obligation_id
        OR NEW.origin_financial_account_id <>
            OLD.origin_financial_account_id
        OR NEW.beneficiary_business_party_id <>
            OLD.beneficiary_business_party_id
        OR NEW.requested_by_user_id <>
            OLD.requested_by_user_id
        OR NEW.amount_minor <> OLD.amount_minor
        OR NEW.currency_code <> OLD.currency_code
        OR COALESCE(NEW.request_note, '') <>
            COALESCE(OLD.request_note, '')
        OR NEW.request_idempotency_key <>
            OLD.request_idempotency_key
        OR NEW.fingerprint <> OLD.fingerprint
        OR NEW.requested_at <> OLD.requested_at
        OR NEW.created_at <> OLD.created_at
        OR OLD.status IN (
            'rejected',
            'cancelled',
            'expired'
        )
        OR NOT (
            (
                OLD.status = 'pending'
                AND NEW.status = 'approved'
                AND NEW.approved_by_user_id IS NOT NULL
                AND NEW.approved_by_user_id <>
                    NEW.requested_by_user_id
                AND NEW.approval_idempotency_key IS NOT NULL
                AND CHAR_LENGTH(
                    TRIM(NEW.approval_idempotency_key)
                ) > 0
                AND NEW.approval_fingerprint IS NOT NULL
                AND CHAR_LENGTH(
                    NEW.approval_fingerprint
                ) = 64
                AND NEW.approved_at IS NOT NULL
                AND NEW.resolved_by_user_id IS NULL
                AND NEW.resolution_idempotency_key IS NULL
                AND NEW.resolution_note IS NULL
                AND NEW.resolved_at IS NULL
                AND EXISTS (
                    SELECT 1
                    FROM organization_memberships membership
                    WHERE membership.organization_id =
                        NEW.organization_id
                      AND membership.user_id =
                        NEW.approved_by_user_id
                      AND membership.active = 1
                      AND membership.role = 'admin'
                )
                AND EXISTS (
                    SELECT 1
                    FROM financial_accounts account
                    WHERE account.id =
                        NEW.origin_financial_account_id
                      AND account.organization_id =
                        NEW.organization_id
                      AND account.active = 1
                      AND account.currency_code =
                        NEW.currency_code
                )
            )
            OR (
                OLD.status = 'pending'
                AND NEW.status IN (
                    'rejected',
                    'cancelled',
                    'expired'
                )
                AND NEW.approved_by_user_id IS NULL
                AND NEW.approval_idempotency_key IS NULL
                AND NEW.approval_fingerprint IS NULL
                AND NEW.approval_note IS NULL
                AND NEW.approved_at IS NULL
                AND NEW.resolved_by_user_id IS NOT NULL
                AND NEW.resolution_idempotency_key IS NOT NULL
                AND CHAR_LENGTH(
                    TRIM(NEW.resolution_idempotency_key)
                ) > 0
                AND NEW.resolution_note IS NOT NULL
                AND CHAR_LENGTH(
                    TRIM(NEW.resolution_note)
                ) > 0
                AND NEW.resolved_at IS NOT NULL
                AND (
                    (
                        NEW.status = 'cancelled'
                        AND NEW.resolved_by_user_id =
                            NEW.requested_by_user_id
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM organization_memberships membership
                        WHERE membership.organization_id =
                            NEW.organization_id
                          AND membership.user_id =
                            NEW.resolved_by_user_id
                          AND membership.active = 1
                          AND membership.role = 'admin'
                          AND (
                              NEW.status = 'cancelled'
                              OR NEW.resolved_by_user_id <>
                                  NEW.requested_by_user_id
                          )
                    )
                )
            )
            OR (
                OLD.status = 'approved'
                AND NEW.status IN (
                    'cancelled',
                    'expired'
                )
                AND NEW.approved_by_user_id =
                    OLD.approved_by_user_id
                AND NEW.approval_idempotency_key =
                    OLD.approval_idempotency_key
                AND NEW.approval_fingerprint =
                    OLD.approval_fingerprint
                AND COALESCE(NEW.approval_note, '') =
                    COALESCE(OLD.approval_note, '')
                AND NEW.approved_at = OLD.approved_at
                AND NEW.resolved_by_user_id IS NOT NULL
                AND NEW.resolution_idempotency_key IS NOT NULL
                AND CHAR_LENGTH(
                    TRIM(NEW.resolution_idempotency_key)
                ) > 0
                AND NEW.resolution_note IS NOT NULL
                AND CHAR_LENGTH(
                    TRIM(NEW.resolution_note)
                ) > 0
                AND NEW.resolved_at IS NOT NULL
                AND (
                    (
                        NEW.status = 'cancelled'
                        AND NEW.resolved_by_user_id =
                            NEW.requested_by_user_id
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM organization_memberships membership
                        WHERE membership.organization_id =
                            NEW.organization_id
                          AND membership.user_id =
                            NEW.resolved_by_user_id
                          AND membership.active = 1
                          AND membership.role = 'admin'
                          AND (
                              NEW.status = 'cancelled'
                              OR NEW.resolved_by_user_id <>
                                  NEW.requested_by_user_id
                          )
                    )
                )
            )
        )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'Transición o mutación inválida de solicitud de pago.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_payment_requests_guard_delete
BEFORE DELETE ON purchase_payment_requests
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'Una solicitud de pago no puede eliminarse.';
END
SQL);
    }
};
